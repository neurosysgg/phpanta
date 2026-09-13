<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RetryAfter;
use Phpanta\Http\ServerVariable;
use Phpanta\Http\Session;
use Phpanta\Http\SessionSeal;
use Phpanta\Service\Login;
use Phpanta\Support\Directory;
use Phpanta\Support\PasswordHash;
use Phpanta\Support\Throttle;
use Phpanta\Support\ThrottleVerdict;
use Phpanta\Test\TestRequest;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A login: the right pair opens a session, anything else does not — and too many tries, from one
 * address at one name, stop being asked at all.
 */
#[CoversClass(Login::class)]
#[CoversClass(Throttle::class)]
#[CoversClass(ThrottleVerdict::class)]
#[CoversClass(Session::class)]
#[CoversClass(RetryAfter::class)]
#[CoversClass(PasswordHash::class)]
final class LoginTest extends TestCase
{
    /** How many attempts one address may make at one name here. */
    private const int LIMIT = 3;

    /** A directory of this test's own, for the throttle. */
    private string $scratch;

    private Login $login;

    private SessionSeal $seal;

    private PasswordHash $hash;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/phpanta-login-' . bin2hex(random_bytes(6));
        mkdir($this->scratch);

        $this->login = new Login(new Throttle(new Directory($this->scratch), self::LIMIT, 60));
        $this->seal  = SessionSeal::fromKey(random_bytes(32));
        $this->hash  = new PasswordHash(password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 4]));
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

    /**
     * The right pair opens a session, logged in and with a form token.
     *
     * @return void
     */
    public function testTheRightPairLogsIn(): void
    {
        $result = $this->tryLogin('ada', 'hunter2');

        self::assertInstanceOf(Session::class, $result);
        self::assertSame('ada', $result->user());
        self::assertNotNull($result->token());
    }

    /**
     * A wrong password and a name the site does not know are the same answer.
     *
     * @return void
     */
    public function testAWrongPasswordAndAnUnknownNameAreBothNothing(): void
    {
        self::assertNull($this->tryLogin('ada', 'wrong'));
        self::assertNull($this->tryLogin('nobody', 'hunter2', known: false));
    }

    /**
     * Once there have been too many, the answer is a 429 with a `Retry-After` in the visitor's
     * language — and the right password is refused too, or the limit would tell the attacker when
     * they had guessed.
     *
     * @return void
     */
    public function testTooManyAttemptsAreRefusedWhateverThePassword(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            self::assertNull($this->tryLogin('ada', 'wrong'));
        }

        $refused = $this->tryLogin('ada', 'hunter2', language: 'de');

        self::assertInstanceOf(Response::class, $refused);

        $answer = $refused->answer(TestRequest::get('/')->request());

        self::assertSame(HttpStatusCode::TooManyRequests, $answer->status());
        self::assertGreaterThan(0, (int) $answer->header(ResponseHeader::RetryAfter)?->value->render());
        self::assertSame('no-store, private', $answer->header(ResponseHeader::CacheControl)?->value->render());
        self::assertSame(FrameworkText::TooManyRequests->in(Language::German) . "\n", $answer->body());
    }

    /**
     * A successful login forgets the failures before it, so a visitor who mistyped twice does not
     * carry them into the next time.
     *
     * @return void
     */
    public function testALoginForgetsTheFailuresBeforeIt(): void
    {
        self::assertNull($this->tryLogin('ada', 'wrong'));
        self::assertNull($this->tryLogin('ada', 'wrong'));
        self::assertInstanceOf(Session::class, $this->tryLogin('ada', 'hunter2'));

        for ($i = 0; $i < self::LIMIT; $i++) {
            self::assertNull($this->tryLogin('ada', 'wrong'), "attempt $i after the login was refused early");
        }
    }

    /**
     * One name's count is its own, from one address; a name counts the same in any case.
     *
     * @return void
     */
    public function testNamesAndAddressesAreCountedApart(): void
    {
        for ($i = 0; $i < self::LIMIT; $i++) {
            self::assertNull($this->tryLogin($i === 0 ? 'Ada' : 'ada', 'wrong'));
        }

        self::assertInstanceOf(Response::class, $this->tryLogin('ADA', 'hunter2'), 'the name was counted by its case');
        self::assertInstanceOf(Session::class, $this->tryLogin('bob', 'hunter2'), 'another name was refused');
        self::assertInstanceOf(
            Session::class,
            $this->tryLogin('ada', 'hunter2', address: '198.51.100.7'),
            'another address was refused',
        );
    }

    /**
     * @param string $user
     * @param string $password
     * @param bool   $known    Whether the site keeps a digest for $user.
     * @param string $address
     * @param string $language
     * @return Session|Response|null
     */
    private function tryLogin(
        string $user,
        string $password,
        bool $known = true,
        string $address = '203.0.113.9',
        string $language = 'en',
    ): Session|Response|null {
        return $this->login->attempt(
            self::request($address, $language),
            Session::fresh($this->seal),
            $user,
            $password,
            $known ? $this->hash : null,
        );
    }

    /**
     * @param string $address
     * @param string $language
     * @return Request
     */
    private static function request(string $address, string $language): Request
    {
        return TestRequest::to(HttpMethod::Post, '/login')
            ->withServer(ServerVariable::RemoteAddress, $address)
            ->with(\Phpanta\Http\RequestHeader::AcceptLanguage, $language)
            ->request();
    }
}
