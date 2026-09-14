<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\Controller\Layer;
use Phpanta\Controller\Layered;
use Phpanta\Exception\SessionException;
use Phpanta\Http\Answer;
use Phpanta\Http\CookieName;
use Phpanta\Http\CsrfField;
use Phpanta\Http\EmptyResponse;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\RequestCookies;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\SealContext;
use Phpanta\Http\ServerVariable;
use Phpanta\Http\Session;
use Phpanta\Http\SessionSeal;
use Phpanta\Http\SetCookie;
use Phpanta\Http\WithHeaders;
use Phpanta\Service\Layer\CsrfGuard;
use Phpanta\Service\Layer\LoginGate;
use Phpanta\Support\Collection;
use Phpanta\Support\File;
use Phpanta\Test\TestRequest;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The session a visitor carries in a sealed cookie — kept, opened, refused, expired — and the two
 * layers that stand on it: the form token and the login.
 */
#[CoversClass(Session::class)]
#[CoversClass(SessionSeal::class)]
#[CoversClass(SetCookie::class)]
#[CoversClass(RequestCookies::class)]
#[CoversClass(WithHeaders::class)]
#[CoversClass(CsrfGuard::class)]
#[CoversClass(LoginGate::class)]
#[CoversClass(Request::class)]
#[CoversClass(App::class)]
final class SessionTest extends TestCase
{
    /** The seal every session here is kept under. */
    private SessionSeal $seal;

    /** A directory of this test's own, for key files. */
    private string $scratch;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->seal    = SessionSeal::fromKey(random_bytes(32));
        $this->scratch = sys_get_temp_dir() . '/phpanta-session-' . bin2hex(random_bytes(6));
        mkdir($this->scratch);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (scandir($this->scratch) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink("$this->scratch/$name");
            }
        }

        rmdir($this->scratch);
    }

    // ───────────────────────── kept, and opened ─────────────────────────

    /**
     * What was kept comes back on the next request, exactly.
     *
     * @return void
     */
    public function testASessionComesBackAsItWasKept(): void
    {
        $kept   = Session::fresh($this->seal)->with(SessionKeyFixture::Cart, '3 items')->withUser('ada');
        $opened = Session::of($this->carrying($kept), $this->seal);

        self::assertSame('3 items', $opened->get(SessionKeyFixture::Cart));
        self::assertSame('ada', $opened->user());
        self::assertSame($kept->token(), $opened->token());
    }

    /**
     * No cookie is an anonymous visitor.
     *
     * @return void
     */
    public function testNoCookieIsAFreshSession(): void
    {
        $session = Session::of(TestRequest::get('/')->request(), $this->seal);

        self::assertNull($session->user());
        self::assertNull($session->token());
        self::assertTrue($session->messages()->isEmpty());
    }

    /**
     * A cookie that does not open — changed by a byte, sealed under another key, cut short, not a seal
     * at all — is simply no session.
     *
     * @param string $case
     * @return void
     */
    #[DataProvider('unopenedProvider')]
    public function testACookieThatDoesNotOpenIsNoSession(string $case): void
    {
        $sealed = $this->sealedValue(Session::fresh($this->seal)->withUser('ada'));

        $flipped = str_ends_with(substr($sealed, 0, -2), 'A') ? 'B' : 'A';
        $other   = Session::fresh(SessionSeal::fromKey(random_bytes(32)))->withUser('ada');

        $cookie = match ($case) {
            'tampered'      => substr($sealed, 0, -3) . $flipped . substr($sealed, -2),
            'another key'   => $this->sealedValue($other),
            'cut short'     => substr($sealed, 0, 20),
            'not base64url' => '!!!not a seal!!!',
        };

        $request = TestRequest::get('/')->with(RequestHeader::Cookie, '__Host-session=' . $cookie)->request();

        self::assertNull(Session::of($request, $this->seal)->user());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unopenedProvider(): iterable
    {
        foreach (['tampered', 'another key', 'cut short', 'not base64url'] as $case) {
            yield $case => [$case];
        }
    }

    /**
     * Something sealed under the same key that is not a session this class wrote is no session
     * either — and a value that is not a string is not kept.
     *
     * @return void
     */
    public function testAPayloadOfAnotherShapeIsNoSession(): void
    {
        foreach (['not json', '[1, 2]', '{"v": {"_user": "ada"}}', '{"e": "soon", "v": {}}'] as $payload) {
            self::assertNull(Session::of($this->sealing($payload), $this->seal)->user(), $payload);
        }

        $mixed   = json_encode(['e' => time() + 60, 'v' => ['_user' => 'ada', 'cart' => 3], 'm' => [7]]);
        $session = Session::of($this->sealing((string) $mixed), $this->seal);

        self::assertSame('ada', $session->user());
        self::assertNull($session->get(SessionKeyFixture::Cart));
        self::assertTrue($session->messages()->isEmpty());
    }

    /**
     * A session is kept for its lifetime after the last answer that carried it, and not a second more.
     *
     * @return void
     */
    public function testASessionExpiresAfterItsLifetime(): void
    {
        $now     = 1_800_000_000;
        $request = $this->carrying(Session::fresh($this->seal)->withUser('ada'), $now);

        self::assertSame('ada', Session::of($request, $this->seal, $now + Session::LIFETIME)->user());
        self::assertNull(Session::of($request, $this->seal, $now + Session::LIFETIME + 1)->user());
    }

    // ───────────────────────── the cookie ─────────────────────────

    /**
     * The cookie is `__Host-`, `Secure`, `HttpOnly`, `SameSite=Lax`, for the whole site, for the
     * session's lifetime — and ending a session expires it with the same attributes, which a `__Host-`
     * cookie needs to be replaced at all.
     *
     * @return void
     */
    public function testTheCookieIsHostBoundAndEndingASessionExpiresIt(): void
    {
        $set = self::setCookie(Session::fresh($this->seal)->attachTo(new EmptyResponse()));

        self::assertStringStartsWith('__Host-session=', $set);
        self::assertStringEndsWith('; Path=/; Max-Age=1209600; SameSite=Lax; Secure; HttpOnly', $set);
        self::assertSame(
            '__Host-session=; Path=/; Max-Age=0; SameSite=Lax; Secure; HttpOnly',
            self::setCookie(Session::endOn(new EmptyResponse())),
        );
        self::assertSame('__Host-session', CookieName::Session->value);
    }

    /**
     * A session sealed larger than a cookie holds is refused rather than cut.
     *
     * @return void
     */
    public function testASessionTooLargeForACookieIsRefused(): void
    {
        $this->expectException(SessionException::class);

        $large = Session::fresh($this->seal)->with(SessionKeyFixture::Cart, str_repeat('x', 4000));

        (void) $large->attachTo(new EmptyResponse());
    }

    // ───────────────────────── who, and the token ─────────────────────────

    /**
     * Logging in hands out a new token, so one a page handed out before is worth nothing after; a
     * session that has a token keeps it; logging out forgets everything.
     *
     * @return void
     */
    public function testLoggingInRotatesTheTokenAndLoggingOutForgetsEverything(): void
    {
        $before = Session::fresh($this->seal)->withToken();

        self::assertNotNull($before->token());
        self::assertSame($before->token(), $before->withToken()->token(), 'a token it had was replaced');

        $in = $before->with(SessionKeyFixture::Cart, 'x')->withUser('ada');

        self::assertNotSame($before->token(), $in->token());

        $out = $in->withoutUser();

        self::assertNull($out->user());
        self::assertNull($out->token());
        self::assertNull($out->get(SessionKeyFixture::Cart));
    }

    /**
     * @return void
     */
    public function testWithoutForgetsOneKeyAndKeepsTheRest(): void
    {
        $session = Session::fresh($this->seal)
            ->with(SessionKeyFixture::Cart, 'x')
            ->withUser('ada')
            ->without(SessionKeyFixture::Cart);

        self::assertNull($session->get(SessionKeyFixture::Cart));
        self::assertSame('ada', $session->user());
    }

    /**
     * A site's key may not begin with `_`, where the framework keeps its own — or a site could log a
     * visitor in by setting a value.
     *
     * @return void
     */
    public function testASiteKeyMayNotBeginWithAnUnderscore(): void
    {
        $this->expectException(SessionException::class);

        (void) Session::fresh($this->seal)->with(SessionKeyFixture::Reserved, 'ada');
    }

    // ───────────────────────── messages ─────────────────────────

    /**
     * A message is carried to the next page as its catalog case, shown in that page's language, and
     * shown once; one whose case no longer exists is skipped rather than shown as its key.
     *
     * @return void
     */
    public function testAMessageIsCarriedAsItsCaseAndShownOnce(): void
    {
        $left   = Session::fresh($this->seal)->withMessage(FrameworkText::LoginRequired);
        $opened = Session::of($this->carrying($left), $this->seal);

        self::assertSame([FrameworkText::LoginRequired], $opened->messages()->toValues());
        self::assertSame('Bitte melde dich zuerst an.', $opened->messages()->toValues()[0]->in(Language::German));
        self::assertTrue($opened->withoutMessages()->messages()->isEmpty());

        $renamed = json_encode([
            'e' => time() + 60,
            'v' => [],
            'm' => ['Nowhere::x', FrameworkText::class . '::gone', FrameworkText::class . '::login-required'],
        ]);
        $request = $this->sealing((string) $renamed);

        self::assertSame([FrameworkText::LoginRequired], Session::of($request, $this->seal)->messages()->toValues());
    }

    // ───────────────────────── the key ─────────────────────────

    /**
     * A key is thirty-two bytes, base64 in its file; anything else is refused, loudly, and a missing
     * file says how to mint one.
     *
     * @return void
     */
    public function testAKeyFileIsReadOrRefusedLoudly(): void
    {
        $file = new File("$this->scratch/session.key");
        $file->write(base64_encode(random_bytes(32)) . "\n");

        $seal = SessionSeal::fromFile($file);
        self::assertSame('hello', $seal->open($seal->seal('hello', SealContext::Session), SealContext::Session));
        self::assertNull(
            $seal->open($seal->seal('hello', SealContext::Enrolment), SealContext::Session),
            'bytes sealed as one thing opened as another',
        );

        foreach (
            [
                'missing'     => [null, 'Mint one on the host it serves'],
                'not base64'  => ['not base64!!', 'does not hold a base64 key'],
                'too short'   => [base64_encode(random_bytes(16)), 'is 32 bytes, not 16'],
            ] as $case => [$contents, $message]
        ) {
            $key = new File("$this->scratch/$case.key");

            if ($contents !== null) {
                $key->write($contents);
            }

            try {
                (void) SessionSeal::fromFile($key);
                self::fail("$case was accepted");
            } catch (SessionException $refused) {
                self::assertStringContainsString($message, $refused->getMessage(), $case);
            }
        }
    }

    /**
     * An app with no session key refuses to keep a session, rather than keeping one under something
     * it made up.
     *
     * @return void
     */
    public function testADeploymentWithNoKeyRefusesToKeepASession(): void
    {
        $this->expectException(SessionException::class);

        (void) TestRequest::get('/')->request()->session();
    }

    // ───────────────────────── the form token ─────────────────────────

    /**
     * A read passes the guard untouched; a write reaches its controller only with the token its
     * session handed out — and is refused, not stored, otherwise.
     *
     * @return void
     */
    public function testAWriteReachesItsControllerOnlyWithItsToken(): void
    {
        $session = Session::fresh($this->seal)->withToken();
        $token   = (string) $session->token();

        self::assertSame('page GET', $this->guarded(TestRequest::get('/')->request())->body());
        self::assertSame('page POST', $this->guarded($this->posted($session, '_csrf=' . $token))->body());

        // A form that sends a file carries its token the same way, read from what PHP parsed.
        $multipart = TestRequest::to(HttpMethod::Post, '/')
            ->with(RequestHeader::Cookie, '__Host-session=' . $this->sealedValue($session))
            ->withField(CsrfField::Token, $token)
            ->request();
        self::assertSame('page POST', $this->guarded($multipart)->body());

        foreach (
            [
                'no token in the session' => $this->posted(Session::fresh($this->seal), '_csrf=' . $token),
                'no field'                => $this->posted($session, 'q=1'),
                'the wrong field'         => $this->posted($session, '_csrf=' . str_repeat('0', 64)),
                'an unread form'          => $this->posted($session, '_csrf=' . $token, 'text/plain'),
            ] as $case => $request
        ) {
            $answer = $this->guarded($request);

            self::assertSame(HttpStatusCode::Forbidden, $answer->status(), $case);
            self::assertSame(FrameworkText::CsrfRefused->in(Language::English) . "\n", $answer->body(), $case);
            self::assertSame('no-store, private', $answer->header(ResponseHeader::CacheControl)?->value->render());
        }

        self::assertSame('_csrf', CsrfField::Token->value);
    }

    // ───────────────────────── the login ─────────────────────────

    /**
     * A logged-in visitor reaches the page; anyone else is sent to the login page for a read and
     * refused for a write, and neither answer is kept.
     *
     * @return void
     */
    public function testOnlyALoggedInVisitorReachesAPageBehindTheLogin(): void
    {
        $gate = Layered::around(
            new Collection(Layer::class)->with(new LoginGate(ExportFixturePath::Guide, $this->seal)),
            new EchoController('page'),
        );

        $in = $this->carrying(Session::fresh($this->seal)->withUser('ada'));
        self::assertSame('page GET', $gate->handle($in)->answer($in)->body());

        $read   = TestRequest::get('/')->request();
        $answer = $gate->handle($read)->answer($read);

        self::assertSame(HttpStatusCode::SeeOther, $answer->status());
        self::assertSame('/guide', $answer->header(ResponseHeader::Location)?->value->render());
        self::assertSame('no-store, private', $answer->header(ResponseHeader::CacheControl)?->value->render());

        $write  = TestRequest::to(HttpMethod::Post, '/')->request();
        $answer = $gate->handle($write)->answer($write);

        self::assertSame(HttpStatusCode::Forbidden, $answer->status());
        self::assertSame(FrameworkText::LoginRequired->in(Language::English) . "\n", $answer->body());
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * A request carrying $session in its cookie, sealed at $now.
     *
     * @param Session  $session
     * @param int|null $now
     * @return Request
     */
    private function carrying(Session $session, ?int $now = null): Request
    {
        return TestRequest::get('/')
            ->with(RequestHeader::Cookie, 'lang=de; __Host-session=' . $this->sealedValue($session, $now))
            ->request();
    }

    /**
     * The value $session's cookie would carry.
     *
     * @param Session  $session
     * @param int|null $now
     * @return string
     */
    private function sealedValue(Session $session, ?int $now = null): string
    {
        $set = self::setCookie($session->attachTo(new EmptyResponse(), $now));

        return substr($set, strlen('__Host-session='), strpos($set, ';') - strlen('__Host-session='));
    }

    /**
     * A POST carrying $session and $body, said to be $type.
     *
     * @param Session $session
     * @param string  $body
     * @param string  $type
     * @return Request
     */
    private function posted(
        Session $session,
        string $body,
        string $type = 'application/x-www-form-urlencoded',
    ): Request {
        return TestRequest::to(HttpMethod::Post, '/')
            ->with(RequestHeader::Cookie, '__Host-session=' . $this->sealedValue($session))
            ->withServer(ServerVariable::ContentType, $type)
            ->withBody($body)
            ->request();
    }

    /**
     * What a page behind the form-token guard answers $request with.
     *
     * @param Request $request
     * @return Answer
     */
    private function guarded(Request $request): Answer
    {
        $guard = new Collection(Layer::class)->with(new CsrfGuard($this->seal));

        return Layered::around($guard, new EchoController('page'))
            ->handle($request)
            ->answer($request);
    }

    /**
     * The `Set-Cookie` $response would send.
     *
     * @param \Phpanta\Http\Response $response
     * @return string
     */
    private static function setCookie(\Phpanta\Http\Response $response): string
    {
        $answer = $response->answer(TestRequest::get('/')->request());

        return (string) $answer->header(ResponseHeader::SetCookie)?->value->render();
    }

    /**
     * A request carrying $payload sealed under this test's key — a session this class did not write.
     *
     * @param string $payload
     * @return Request
     */
    private function sealing(string $payload): Request
    {
        return TestRequest::get('/')
            ->with(RequestHeader::Cookie, '__Host-session=' . $this->seal->seal($payload, SealContext::Session))
            ->request();
    }
}
