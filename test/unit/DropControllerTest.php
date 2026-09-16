<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\Controller\DropController;
use Phpanta\Controller\UnroutedController;
use Phpanta\CredentialFile;
use Phpanta\Http\Answer;
use Phpanta\Http\DropField;
use Phpanta\Http\FormEncoding;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\MultipartParameters;
use Phpanta\Http\Request;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\ServerParameters;
use Phpanta\Http\ServerVariable;
use Phpanta\Model\Drop\DropConfig;
use Phpanta\Model\Drop\DropHeader;
use Phpanta\Model\Drop\DropMeta;
use Phpanta\Model\Drop\DropRefusal;
use Phpanta\Model\Drop\DropToken;
use Phpanta\Service\Drop\DropCipher;
use Phpanta\Service\Drop\DropStore;
use Phpanta\Service\Drop\OpenedDrop;
use Phpanta\Support\Directory;
use Phpanta\Support\DropPath;
use Phpanta\Support\Throttle;
use Phpanta\Test\TestRequest;
use Phpanta\Text\DropText;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Language;
use Phpanta\View\DropTextView;
use Phpanta\View\DropView;
use Phpanta\View\Html\DropTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `/drop`, as a stranger holding a link meets it: an address that is not there wherever drops are off;
 * where they are on, a page asking for the link's token, and a post that reveals what the drop holds —
 * text on a page or as itself, a file as bytes under its name — or says, one way for every kind of
 * nothing, that there is nothing here. A password is asked for only of a link that holds one, and every
 * refusal is counted.
 *
 * Asked through the app, so the route, the router and the security headers are the real ones; the
 * switch and the key are the test app's own, written by a test that switches drops on.
 */
#[CoversClass(DropController::class)]
#[CoversClass(DropStore::class)]
#[CoversClass(OpenedDrop::class)]
#[CoversClass(DropCipher::class)]
#[CoversClass(DropHeader::class)]
#[CoversClass(DropMeta::class)]
#[CoversClass(DropToken::class)]
#[CoversClass(DropConfig::class)]
#[CoversClass(DropRefusal::class)]
#[CoversClass(DropView::class)]
#[CoversClass(DropTextView::class)]
#[CoversClass(DropText::class)]
#[CoversClass(DropField::class)]
#[CoversClass(DropTag::class)]
#[CoversClass(DropPath::class)]
#[CoversClass(UnroutedController::class)]
#[CoversClass(App::class)]
final class DropControllerTest extends TestCase
{
    /** The PBKDF2 rounds a password is stretched with here. */
    private const int ROUNDS = 1_000;

    /** Whether the test app's data directory was made here, and so is taken away here. */
    private static bool $madeData = false;

    /** Whether its throttle directory was. */
    private static bool $madeThrottles = false;

    /** The address this test posts from — its own, so no test counts another's refusals. */
    private string $address = '';

    /**
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        self::$madeData      = !App::current()->data()->exists() && App::current()->data()->create();
        self::$madeThrottles = !App::current()->throttles()->exists() && App::current()->throttles()->create();
    }

    /**
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$madeThrottles) {
            UpdateFixture::removeTree(App::current()->throttles()->path);
        }

        if (self::$madeData) {
            rmdir(App::current()->data()->path);
        }
    }

    /**
     * Drops off, and an address of this test's own.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->address = '192.0.2.' . random_int(1, 254);
        $this->switchOff();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->switchOff();
        (void) new Throttle(App::current()->throttles(), 1, 1)->clear($this->counted());

        $drops = App::current()->data()->directory(DropStore::DIRECTORY);

        if ($drops->exists()) {
            UpdateFixture::removeTree($drops->path);
        }
    }

    // ───────────────────────── off ─────────────────────────

    /**
     * Off, `/drop` answers every method exactly as an address that is not there — status, body and
     * `Allow` — so nothing says a drop could be posted here. A switch without a key is off too, and a
     * key without a switch.
     *
     * @return void
     */
    public function testOffItAnswersAsAnAddressThatIsNotThere(): void
    {
        $methods = [HttpMethod::Get, HttpMethod::Head, HttpMethod::Post, HttpMethod::Put, HttpMethod::Options, 'BREW'];

        foreach ([null, CredentialFile::Drop, CredentialFile::DropKey] as $only) {
            $this->switchOff();

            if ($only === CredentialFile::Drop) {
                self::assertTrue(App::current()->dataFile(CredentialFile::Drop)->write('{}'));
            }

            if ($only === CredentialFile::DropKey) {
                $key = base64_encode(random_bytes(DropCipher::KEY_BYTES));
                self::assertTrue(App::current()->dataFile(CredentialFile::DropKey)->write($key));
            }

            foreach ($methods as $method) {
                $drop   = TestRequest::to($method, DropPath::Index->to())->answer();
                $absent = TestRequest::to($method, '/no-such-page')->answer();
                $verb   = $method instanceof HttpMethod ? $method->value : $method;
                $said   = sprintf('%s with %s', $verb, $only?->value ?? 'nothing');

                self::assertSame($absent->status(), $drop->status(), $said);
                self::assertSame($absent->body(), $drop->body(), $said);
                self::assertSame(self::allowed($absent), self::allowed($drop), $said);
            }
        }
    }

    // ───────────────────────── on ─────────────────────────

    /**
     * On, a read is the page: a form posting the token and a password back here, the token's field
     * inside the element that fills it in from the link — kept by no cache, and not to be indexed.
     *
     * @return void
     */
    public function testOnAReadIsThePageThatAsksForTheToken(): void
    {
        $this->switchOn();

        $page = TestRequest::get(DropPath::Index->to())->answer();
        $body = $page->body();

        self::assertSame(HttpStatusCode::Ok, $page->status());
        self::assertStringContainsString('<form method="post" action="/drop">', $body);
        self::assertMatchesRegularExpression(
            '~<drop-reveal>\s*<p>\s*<label>[^<]*<input type="text" name="token" autocomplete="off" required>~',
            $body,
        );
        self::assertStringContainsString('<input type="password" name="password" autocomplete="off">', $body);
        self::assertStringContainsString('<input type="hidden" name="page" value="true">', $body);
        self::assertStringContainsString(DropText::Intro->in(Language::English), $body);
        self::assertStringContainsString('no-store', self::headerOf($page, ResponseHeader::CacheControl));
        self::assertNotNull($page->header(ResponseHeader::Robots));
        $head = TestRequest::to(HttpMethod::Head, DropPath::Index->to())->answer();

        self::assertSame(HttpStatusCode::Ok, $head->status());
    }

    /**
     * On, anything but a read or a post is refused, naming the post.
     *
     * @return void
     */
    public function testOnAnyOtherMethodIsRefusedNamingThePost(): void
    {
        $this->switchOn();

        $put = TestRequest::to(HttpMethod::Put, DropPath::Index->to())->answer();

        self::assertSame(HttpStatusCode::MethodNotAllowed, $put->status());
        self::assertSame('GET, HEAD, POST', self::allowed($put));
    }

    /**
     * Text is revealed on a page to read it on where the page asked — escaped like any text — and as
     * itself to anything else, its length stated.
     *
     * @return void
     */
    public function testTextIsRevealedOnThePageAndAsItselfElsewhere(): void
    {
        $token = $this->make('<b>hi</b> & bye');
        $page  = $this->reveal($token, page: true);

        self::assertSame(HttpStatusCode::Ok, $page->status());
        self::assertStringContainsString('<pre>&lt;b&gt;hi&lt;/b&gt; &amp; bye</pre>', $page->body());
        self::assertStringContainsString(DropText::Revealed->in(Language::English), $page->body());
        self::assertStringContainsString('no-store', self::headerOf($page, ResponseHeader::CacheControl));

        $text = $this->reveal($token);

        self::assertSame('<b>hi</b> & bye', $text->body());
        self::assertSame('text/plain; charset=utf-8', self::headerOf($text, ResponseHeader::ContentType));
        self::assertSame('inline', self::headerOf($text, ResponseHeader::ContentDisposition));
        self::assertSame('15', self::headerOf($text, ResponseHeader::ContentLength));
        self::assertNotNull($text->header(ResponseHeader::Robots));
    }

    /**
     * A file is saved as bytes under its name, whatever it claims to be — never shown, even to the page.
     *
     * @return void
     */
    public function testAFileIsSavedAsBytesUnderItsName(): void
    {
        $file = $this->reveal($this->make('<script>alert(1)</script>', 'page.html'), page: true);

        self::assertSame(HttpStatusCode::Ok, $file->status());
        self::assertSame('<script>alert(1)</script>', $file->body());
        self::assertSame('application/octet-stream', self::headerOf($file, ResponseHeader::ContentType));
        self::assertStringStartsWith(
            'attachment; filename="page.html"',
            self::headerOf($file, ResponseHeader::ContentDisposition),
        );
    }

    /**
     * Text whose file no longer opens whole is nothing, on the page as anywhere — never half of it.
     *
     * @return void
     */
    public function testTextThatDoesNotOpenWholeIsNothing(): void
    {
        $token = $this->make('a line that will not survive');
        $file  = $this->drops()->files('*.drop')->toValues()[0];
        $bytes = (string) $file->read();
        $last  = strlen($bytes) - 1;

        $bytes[$last] = chr(ord($bytes[$last]) ^ 0x01);
        self::assertTrue($file->write($bytes));

        $page = $this->reveal($token, page: true);

        self::assertSame(HttpStatusCode::NotFound, $page->status());
        self::assertStringContainsString(DropText::Absent->in(Language::English), $page->body());
        self::assertStringNotContainsString('survive', $page->body());
    }

    /**
     * A drop meant to be read once is gone once it has been, and its page says so.
     *
     * @return void
     */
    public function testOnceIsGoneAfterItHasBeenRevealed(): void
    {
        $token = $this->make('only once', once: true);
        $first = $this->reveal($token, page: true);

        self::assertSame(HttpStatusCode::Ok, $first->status());
        self::assertStringContainsString(DropText::ReadOnce->in(Language::English), $first->body());
        self::assertSame(HttpStatusCode::NotFound, $this->reveal($token, page: true)->status());
    }

    /**
     * No token, one that is none, one of no drop, one expired and one read already are one answer —
     * on the page and as a line of text alike.
     *
     * @return void
     */
    public function testEveryKindOfNothingIsOneAnswer(): void
    {
        $spent = $this->make('spent', once: true);
        (void) $this->reveal($spent);

        $expired = new DropStore($this->drops(), $this->cipher(), self::ROUNDS)
            ->create('old', null, false, '', 60, time() - 61);

        foreach (['', 'not a token', DropToken::mint()->text(), $expired->text(), $spent] as $token) {
            $page = $this->reveal($token, page: true);
            $text = $this->reveal($token);

            self::assertSame(HttpStatusCode::NotFound, $page->status(), $token);
            self::assertStringContainsString(DropText::Absent->in(Language::English), $page->body());
            self::assertStringNotContainsString('value="' . $token . '"', $page->body(), 'nothing is written back');
            self::assertSame(HttpStatusCode::NotFound, $text->status());
            self::assertSame(DropText::Absent->in(Language::English) . "\n", $text->body());
        }
    }

    /**
     * A password is asked for of a link that holds one — and where one was given, said to be wrong —
     * with the token written back so only the password is asked again.
     *
     * @return void
     */
    public function testAPasswordIsAskedForOnlyOfALinkThatHoldsOne(): void
    {
        $token  = $this->make('behind a password', password: 'hunter2');
        $asked  = $this->reveal($token, page: true);
        $wrong  = $this->reveal($token, 'hunter3', page: true);
        $opened = $this->reveal($token, 'hunter2');

        self::assertSame(HttpStatusCode::Forbidden, $asked->status());
        self::assertStringContainsString(DropText::NeedsPassword->in(Language::English), $asked->body());
        self::assertStringContainsString('value="' . $token . '"', $asked->body());
        self::assertSame(HttpStatusCode::Forbidden, $wrong->status());
        self::assertStringContainsString(DropText::WrongPassword->in(Language::English), $wrong->body());
        self::assertSame('behind a password', $opened->body());
    }

    /**
     * Every refusal is counted against the sender's address, and past the limit a post is refused
     * before any drop is asked anything — while what reveals a drop is never counted.
     *
     * @return void
     */
    public function testRefusalsAreCountedAndPastTheLimitNothingIsAsked(): void
    {
        $token = $this->make('fetched often');

        for ($i = 0; $i < 20; $i++) {
            self::assertSame(HttpStatusCode::Ok, $this->reveal($token)->status(), 'a reveal is never counted');
        }

        for ($i = 0; $i < 10; $i++) {
            self::assertSame(HttpStatusCode::NotFound, $this->reveal('wrong')->status());
        }

        $held = $this->reveal($token, page: true);

        self::assertSame(HttpStatusCode::TooManyRequests, $held->status());
        self::assertSame('900', (string) $held->header(ResponseHeader::RetryAfter)?->value->render());
        self::assertStringContainsString(DropText::Uncounted->in(Language::English), $held->body());
    }

    /**
     * Where refusals cannot be counted nothing is revealed — failing closed.
     *
     * @return void
     */
    public function testWithoutAPlaceToCountNothingIsRevealed(): void
    {
        $token   = $this->make('uncountable');
        $request = TestRequest::to(HttpMethod::Post, DropPath::Index->to())
            ->withField(DropField::Token, $token)
            ->request();
        $answer  = new DropController(null, new Throttle(new Directory('/nonexistent/phpanta/throttle'), 10, 900))
            ->handle($request)
            ->answer($request);

        self::assertSame(HttpStatusCode::ServiceUnavailable, $answer->status());
        self::assertSame(DropText::Uncounted->in(Language::English) . "\n", $answer->body());
    }

    /**
     * A post whose form does not read is the router's 400, like any other.
     *
     * @return void
     */
    public function testAFormSentUnreadablyIsTheRoutersRefusal(): void
    {
        $this->switchOn();

        $request = Request::from(
            new ServerParameters([
                ServerVariable::RequestMethod->value => HttpMethod::Post->value,
                ServerVariable::RequestUri->value    => DropPath::Index->to(),
                ServerVariable::ContentType->value   => FormEncoding::Multipart->value,
            ]),
            null,
            new MultipartParameters([DropField::Token->value => ['one', 'two']], []),
        );
        $answer  = App::current()->handle($request);

        self::assertSame(HttpStatusCode::BadRequest, $answer->status());
        self::assertSame(FrameworkText::BadRequest->in(Language::English) . "\n", $answer->body());
    }

    /**
     * The route is the framework's, delegated and never a page of an export — and a link is its
     * address with the token after the `#`.
     *
     * @return void
     */
    public function testTheRouteIsTheFrameworks(): void
    {
        $routes = App::current()->dropRoutes()->toValues();
        $token  = DropToken::mint();

        self::assertCount(1, $routes);
        self::assertSame(DropPath::Index, $routes[0]->path());
        self::assertTrue($routes[0]->accepts(null));
        self::assertTrue($routes[0]->exportedPaths()->isEmpty());
        self::assertSame('/drop#' . $token->text(), DropPath::Index->link($token));
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * Switches drops on here — with a key already there, the one that was.
     *
     * @return void
     */
    private function switchOn(): void
    {
        self::assertTrue(App::current()->dataFile(CredentialFile::Drop)->write('{}'));

        $key = App::current()->dataFile(CredentialFile::DropKey);

        if (DropCipher::current($key) === null) {
            self::assertTrue($key->write(base64_encode(random_bytes(DropCipher::KEY_BYTES))));
        }
    }

    /**
     * Switches drops off here: no switch, no key.
     *
     * @return void
     */
    private function switchOff(): void
    {
        (void) App::current()->dataFile(CredentialFile::Drop)->delete();
        (void) App::current()->dataFile(CredentialFile::DropKey)->delete();
    }

    /**
     * A drop made in the deployment's store, drops switched on — its token as the link carries it.
     *
     * @param string      $payload
     * @param string|null $name
     * @param bool        $once
     * @param string      $password
     * @return string
     */
    private function make(string $payload, ?string $name = null, bool $once = false, string $password = ''): string
    {
        $this->switchOn();

        return new DropStore($this->drops(), $this->cipher(), self::ROUNDS)
            ->create($payload, $name, $once, $password, 3_600)
            ->text();
    }

    /**
     * What `/drop` answers a post of $token — and $password — from this test's address, as the page's
     * own form or as anything else.
     *
     * @param string $token
     * @param string $password
     * @param bool   $page
     * @return Answer
     */
    private function reveal(string $token, string $password = '', bool $page = false): Answer
    {
        $request = TestRequest::to(HttpMethod::Post, DropPath::Index->to())
            ->withServer(ServerVariable::RemoteAddress, $this->address)
            ->withField(DropField::Token, $token)
            ->withField(DropField::Password, $password);

        return ($page ? $request->withField(DropField::Page, 'true') : $request)->answer();
    }

    /**
     * What this test's refusals are counted under.
     *
     * @return string
     */
    private function counted(): string
    {
        return DropPath::Index->value . ' ' . $this->address;
    }

    /**
     * The deployment's drop store.
     *
     * @return Directory
     */
    private function drops(): Directory
    {
        return App::current()->data()->directory(DropStore::DIRECTORY);
    }

    /**
     * The deployment's cipher.
     *
     * @return DropCipher
     */
    private function cipher(): DropCipher
    {
        $cipher = DropCipher::current();
        self::assertNotNull($cipher);

        return $cipher;
    }

    /**
     * The `Allow` an answer carries, or `''`.
     *
     * @param Answer $answer
     * @return string
     */
    private static function allowed(Answer $answer): string
    {
        return self::headerOf($answer, ResponseHeader::Allow);
    }

    /**
     * What $name says in an answer, or `''` where the answer does not carry it.
     *
     * @param Answer         $answer
     * @param ResponseHeader $name
     * @return string
     */
    private static function headerOf(Answer $answer, ResponseHeader $name): string
    {
        return (string) $answer->header($name)?->value->render();
    }
}
