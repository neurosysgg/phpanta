<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\Controller\Controller;
use Phpanta\CredentialFile;
use Phpanta\Form\Form;
use Phpanta\Form\Submission;
use Phpanta\Http\Answer;
use Phpanta\Http\FormEncoding;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\ServerVariable;
use Phpanta\Http\Session;
use Phpanta\Http\SessionSeal;
use Phpanta\Http\ViewResponse;
use Phpanta\Router;
use Phpanta\Service\Layer\CsrfGuard;
use Phpanta\Service\Layer\LoginGate;
use Phpanta\Service\Login;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\MethodSet;
use Phpanta\Support\PasswordHash;
use Phpanta\Support\Route;
use Phpanta\Support\SearchableCollection;
use Phpanta\Support\Throttle;
use Phpanta\Test\TestRequest;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The login recipe, walked end to end: the page and its token, a form sent without it, one not
 * filled in, a wrong password and an unknown name answered alike, a login that rotates the token,
 * the page behind it, signing out, and too many tries — each answer's cookie carried to the next
 * request, as a browser would carry it.
 *
 * The code under test is the recipe's, as docs/login.md shows it: the controllers call
 * `Request::session()` and list the guards with no seal of their own, so the test app is given a
 * session key of its own for the length of this class, and the key is removed after it.
 */
#[CoversClass(Login::class)]
#[CoversClass(Throttle::class)]
#[CoversClass(Session::class)]
#[CoversClass(SessionSeal::class)]
#[CoversClass(CsrfGuard::class)]
#[CoversClass(LoginGate::class)]
#[CoversClass(Form::class)]
#[CoversClass(Submission::class)]
#[CoversClass(ViewResponse::class)]
#[CoversClass(Router::class)]
#[CoversClass(Request::class)]
#[CoversClass(FrameworkText::class)]
#[CoversClass(App::class)]
final class LoginRecipeTest extends TestCase
{
    /** How many attempts one address may make at one name here. */
    private const int LIMIT = 3;

    /** Where every request here comes from, which the throttle counts by. */
    private const string ADDRESS = '203.0.113.7';

    /** Whether this class made the test app's data/, and so removes it. */
    private static bool $madeData = false;

    /** A directory of this test's own, for the throttle. */
    private string $scratch;

    private Router $router;

    /**
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        $data           = App::current()->data();
        self::$madeData = !$data->exists();

        if (self::$madeData) {
            mkdir($data->path);
        }

        file_put_contents(
            App::current()->dataFile(CredentialFile::SessionKey)->path,
            base64_encode(random_bytes(32)),
        );
    }

    /**
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        unlink(App::current()->dataFile(CredentialFile::SessionKey)->path);

        if (self::$madeData) {
            rmdir(App::current()->data()->path);
        }
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/phpanta-recipe-' . bin2hex(random_bytes(6));
        mkdir($this->scratch);

        $login = new Login(new Throttle(new Directory($this->scratch), self::LIMIT, 900));
        $users = new UsersFixture(new SearchableCollection(PasswordHash::class)->with(
            'ada',
            new PasswordHash(password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 4])),
        ));

        $this->router = new Router(new Collection(Route::class)->with(
            new Route(
                LoginPathFixture::Login,
                static fn(): Controller => new LoginControllerFixture($login, $users),
                MethodSet::of(HttpMethod::Get, HttpMethod::Post),
            )->through(new CsrfGuard()),
            new Route(
                LoginPathFixture::Logout,
                static fn(): Controller => new LogoutControllerFixture(),
                MethodSet::of(HttpMethod::Post),
            )->through(new CsrfGuard()),
            new Route(
                LoginPathFixture::Account,
                static fn(): Controller => new AccountControllerFixture(),
            )->through(new LoginGate(LoginPathFixture::Login)),
        ));
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

    // ───────────────────────── the page ─────────────────────────

    /**
     * The login page is a form with the token it has to post, handed out in a session the page
     * keeps — and kept by no cache.
     *
     * @return void
     */
    public function testTheLoginPageHandsOutAFormAndItsToken(): void
    {
        $page = $this->answer(self::get(LoginPathFixture::Login));

        self::assertSame(HttpStatusCode::Ok, $page->status());
        self::assertStringStartsWith('__Host-session=', self::cookie($page));
        self::assertSame('no-store, private', $page->header(ResponseHeader::CacheControl)?->value->render());
        self::assertStringContainsString('autocomplete="username"', $page->body());
        self::assertStringContainsString('autocomplete="current-password"', $page->body());
        self::assertSame(64, strlen(self::token($page)));
    }

    /**
     * A form sent without its token is refused before the page sees it.
     *
     * @return void
     */
    public function testAFormSentWithoutItsTokenIsRefused(): void
    {
        [$cookie] = $this->arrive();

        $answer = $this->answer(self::post(LoginPathFixture::Login, $cookie, [
            'name'     => 'ada',
            'password' => 'hunter2',
        ]));

        self::assertSame(HttpStatusCode::Forbidden, $answer->status());
        self::assertSame(FrameworkText::CsrfRefused->in(Language::English) . "\n", $answer->body());
    }

    /**
     * A form not filled in is shown again with what is missing, and costs no attempt: more of them
     * than the limit, and the right password still signs in.
     *
     * @return void
     */
    public function testAFormNotFilledInIsShownAgainAndCostsNoAttempt(): void
    {
        [$cookie, $token] = $this->arrive();

        for ($sent = 0; $sent <= self::LIMIT; $sent++) {
            $answer = $this->answer(self::post(LoginPathFixture::Login, $cookie, [
                '_csrf'    => $token,
                'name'     => 'ada',
                'password' => '',
            ]));

            self::assertSame(HttpStatusCode::UnprocessableContent, $answer->status());
            self::assertStringContainsString(FrameworkText::FieldRequired->in(Language::English), $answer->body());
        }

        self::assertSame(HttpStatusCode::SeeOther, $this->signInWith($cookie, $token, 'hunter2')->status());
    }

    /**
     * A wrong password and a name the site does not know are answered with the same page: the name
     * kept, the password never written back, and the one refusal beside the password.
     *
     * @return void
     */
    public function testAWrongPasswordAndAnUnknownNameAreAnsweredAlike(): void
    {
        [$cookie, $token] = $this->arrive();

        $wrong   = $this->answer(self::post(LoginPathFixture::Login, $cookie, [
            '_csrf'    => $token,
            'name'     => 'ada',
            'password' => 'guess',
        ]));
        $unknown = $this->answer(self::post(LoginPathFixture::Login, $cookie, [
            '_csrf'    => $token,
            'name'     => 'bob',
            'password' => 'guess',
        ]));

        self::assertSame(HttpStatusCode::UnprocessableContent, $wrong->status());
        self::assertSame($wrong->status(), $unknown->status());
        self::assertStringContainsString(FrameworkText::LoginRefused->in(Language::English), $wrong->body());
        self::assertStringContainsString('aria-describedby="error-password"', $wrong->body());
        self::assertStringContainsString('value="ada"', $wrong->body());
        self::assertStringNotContainsString('guess', $wrong->body());
        self::assertSame($wrong->body(), str_replace('value="bob"', 'value="ada"', $unknown->body()));
    }

    // ───────────────────────── signed in ─────────────────────────

    /**
     * The right password signs in and sends the visitor on, with a word the next page shows once —
     * and the token written before the login signs nothing after it.
     *
     * @return void
     */
    public function testTheRightPasswordSignsInAndRotatesTheToken(): void
    {
        [$cookie, $before] = $this->arrive();

        $in = $this->signInWith($cookie, $before, 'hunter2');

        self::assertSame(HttpStatusCode::SeeOther, $in->status());
        self::assertSame('/account', $in->header(ResponseHeader::Location)?->value->render());

        $cookie  = self::cookie($in, $cookie);
        $account = $this->answer(self::get(LoginPathFixture::Account, $cookie));
        $said    = LoginTextFixture::SignedIn->in(Language::English);

        self::assertSame(HttpStatusCode::Ok, $account->status());
        self::assertStringContainsString('Signed in as ada.', $account->body());
        self::assertStringContainsString($said, $account->body());
        self::assertNotSame($before, self::token($account));

        $cookie = self::cookie($account, $cookie);
        $again  = $this->answer(self::get(LoginPathFixture::Account, $cookie));

        self::assertStringNotContainsString($said, $again->body(), 'a message is shown once');

        $cookie = self::cookie($again, $cookie);
        $stale  = $this->answer(self::post(LoginPathFixture::Logout, $cookie, ['_csrf' => $before]));

        self::assertSame(HttpStatusCode::Forbidden, $stale->status());
    }

    /**
     * A page behind the login sends a visitor who is not signed in to the login page.
     *
     * @return void
     */
    public function testAPageBehindTheLoginSendsAStrangerToIt(): void
    {
        $answer = $this->answer(self::get(LoginPathFixture::Account));

        self::assertSame(HttpStatusCode::SeeOther, $answer->status());
        self::assertSame('/login', $answer->header(ResponseHeader::Location)?->value->render());
    }

    /**
     * Signing out is a form, sent with its token, that forgets the visitor and says so on the login
     * page — and a read of its address is not a way to sign anybody out.
     *
     * @return void
     */
    public function testSigningOutForgetsTheVisitor(): void
    {
        [$cookie, $token] = $this->arrive();
        $cookie           = self::cookie($this->signInWith($cookie, $token, 'hunter2'), $cookie);
        $account          = $this->answer(self::get(LoginPathFixture::Account, $cookie));
        $cookie           = self::cookie($account, $cookie);

        $out = $this->answer(self::post(LoginPathFixture::Logout, $cookie, ['_csrf' => self::token($account)]));

        self::assertSame(HttpStatusCode::SeeOther, $out->status());
        self::assertSame('/login', $out->header(ResponseHeader::Location)?->value->render());

        $cookie = self::cookie($out, $cookie);

        self::assertSame(
            HttpStatusCode::SeeOther,
            $this->answer(self::get(LoginPathFixture::Account, $cookie))->status(),
        );
        self::assertStringContainsString(
            LoginTextFixture::SignedOut->in(Language::English),
            $this->answer(self::get(LoginPathFixture::Login, $cookie))->body(),
        );
        self::assertSame(
            HttpStatusCode::MethodNotAllowed,
            $this->answer(self::get(LoginPathFixture::Logout, $cookie))->status(),
        );
    }

    /**
     * Too many wrong passwords from one address at one name, and the next answer is a 429 with a
     * `Retry-After` — for the right password too, or the limit would say which one it was.
     *
     * @return void
     */
    public function testTooManyWrongPasswordsStopBeingAsked(): void
    {
        [$cookie, $token] = $this->arrive();

        for ($tried = 0; $tried < self::LIMIT; $tried++) {
            $refusal = $this->signInWith($cookie, $token, 'guess');

            self::assertSame(HttpStatusCode::UnprocessableContent, $refusal->status());
        }

        $refused = $this->signInWith($cookie, $token, 'hunter2');

        self::assertSame(HttpStatusCode::TooManyRequests, $refused->status());
        self::assertNotNull($refused->header(ResponseHeader::RetryAfter));
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * What the recipe's routes answer $request with, sent from {@link self::ADDRESS}.
     *
     * @param TestRequest $request
     * @return Answer
     */
    private function answer(TestRequest $request): Answer
    {
        $built = $request->withServer(ServerVariable::RemoteAddress, self::ADDRESS)->request();

        return $this->router->dispatch($built)->answer($built);
    }

    /**
     * A visitor who has opened the login page: the cookie it set, and the token in its form.
     *
     * @return array{string, string}
     */
    private function arrive(): array
    {
        $page = $this->answer(self::get(LoginPathFixture::Login));

        return [self::cookie($page), self::token($page)];
    }

    /**
     * The login form sent as ada, with $password.
     *
     * @param string $cookie
     * @param string $token
     * @param string $password
     * @return Answer
     */
    private function signInWith(string $cookie, string $token, string $password): Answer
    {
        return $this->answer(self::post(LoginPathFixture::Login, $cookie, [
            '_csrf'    => $token,
            'name'     => 'ada',
            'password' => $password,
        ]));
    }

    /**
     * The session cookie $answer sets, as the next request sends it back — or $previous, where it
     * sets none.
     *
     * @param Answer $answer
     * @param string $previous
     * @return string
     */
    private static function cookie(Answer $answer, string $previous = ''): string
    {
        $set = $answer->header(ResponseHeader::SetCookie)?->value->render();

        return $set === null ? $previous : explode(';', $set, 2)[0];
    }

    /**
     * The form token written into $answer's form.
     *
     * @param Answer $answer
     * @return string
     */
    private static function token(Answer $answer): string
    {
        self::assertSame(1, preg_match('#name="_csrf" value="([0-9a-f]+)"#', $answer->body(), $match));

        return $match[1];
    }

    /**
     * A GET for $path, carrying $cookie where there is one.
     *
     * @param LoginPathFixture $path
     * @param string           $cookie
     * @return TestRequest
     */
    private static function get(LoginPathFixture $path, string $cookie = ''): TestRequest
    {
        $request = TestRequest::get($path->to());

        return $cookie === '' ? $request : $request->with(RequestHeader::Cookie, $cookie);
    }

    /**
     * A form POST to $path carrying $cookie and $fields.
     *
     * @param LoginPathFixture      $path
     * @param string                $cookie
     * @param array<string, string> $fields
     * @return TestRequest
     */
    private static function post(LoginPathFixture $path, string $cookie, array $fields): TestRequest
    {
        return TestRequest::to(HttpMethod::Post, $path->to())
            ->with(RequestHeader::Cookie, $cookie)
            ->withServer(ServerVariable::ContentType, FormEncoding::UrlEncoded->value)
            ->withBody(http_build_query($fields));
    }
}
