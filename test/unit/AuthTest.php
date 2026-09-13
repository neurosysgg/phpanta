<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Exception\InvalidValueException;
use Phpanta\Http\Answer;
use Phpanta\Http\BasicChallenge;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\ResponseHeader;
use Phpanta\Service\Auth;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\PasswordHash;
use Phpanta\Test\TestApp;
use Phpanta\Test\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The comparison every gate ends in, the digest it compares against, and the admin gate around it.
 *
 * **Nothing else reaches the comparison.** A repository's `data/admin.php` ships with an empty
 * `pass_hash`, so {@link Auth::accepts()} short-circuits on its first operand and neither
 * `hash_equals()` nor `password_verify()` runs — an end-to-end check that an admin route answers
 * 401 proves the route is gated without ever comparing a credential. These tests write a real
 * bcrypt hash into a credentials file of their own, so the comparison itself is what is under test.
 *
 * The site gate's refusal, its right pair and its absent file are {@link AnswerTest}'s, which
 * asserts them through the answer; what is here is the admin gate beside it and the decision both
 * of them ask.
 */
#[CoversClass(Auth::class)]
#[CoversClass(PasswordHash::class)]
#[CoversClass(BasicChallenge::class)]
#[CoversClass(PlainTextResponse::class)]
#[CoversClass(Answer::class)]
final class AuthTest extends TestCase
{
    private Directory $fixtures;

    /** Names one fixture from the next inside the one directory. */
    private int $written = 0;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->fixtures = Directory::temporary('phpanta-auth-');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->fixtures->files() as $file) {
            $file->delete();
        }

        $this->fixtures->remove();
    }

    // ───────────────────────────── fixtures ─────────────────────────────

    /**
     * A credentials file of the shape both gates read.
     *
     * Cost 4 is bcrypt's minimum and keeps the suite fast; `password_verify()` reads the cost out
     * of the hash, so this exercises exactly the same code path a production hash does.
     *
     * @param string $user
     * @param string $password The empty string writes an empty hash: an unconfigured gate.
     * @param int    $cost
     * @return File
     */
    private function credentials(string $user, string $password, int $cost = 4): File
    {
        $hash = $password === '' ? '' : password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
        $file = $this->fixtures->file(++$this->written . '.php');

        $file->write('<?php return ' . var_export(['user' => $user, 'pass_hash' => $hash], true) . ';');

        return $file;
    }

    /**
     * @param string $user
     * @param string $password
     * @return Request
     */
    private static function request(string $user, string $password): Request
    {
        return TestRequest::get('/admin')->withCredentials($user, $password)->request();
    }

    /**
     * Cost 4, for the same reason as above.
     *
     * @param string $password
     * @return PasswordHash
     */
    private static function hash(string $password): PasswordHash
    {
        return new PasswordHash(password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]));
    }

    // ───────────────────────────── the decision ─────────────────────────────

    /**
     * @return void
     */
    public function testTheRightUserAndPasswordAreAccepted(): void
    {
        self::assertTrue(Auth::accepts(self::request('admin', 'hunter2'), $this->credentials('admin', 'hunter2')));
    }

    /**
     * The comparison an end-to-end check never runs.
     *
     * @param string $user
     * @param string $password
     * @return void
     */
    #[DataProvider('wrongCredentialProvider')]
    public function testAnythingOtherThanTheRightPairIsRejected(string $user, string $password): void
    {
        self::assertFalse(Auth::accepts(self::request($user, $password), $this->credentials('admin', 'hunter2')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wrongCredentialProvider(): iterable
    {
        yield 'wrong password'        => ['admin', 'hunter3'];
        yield 'wrong user'            => ['root', 'hunter2'];
        yield 'both wrong'            => ['root', 'hunter3'];
        yield 'no credentials'        => ['', ''];
        yield 'empty password'        => ['admin', ''];
        yield 'empty user'            => ['', 'hunter2'];
        yield 'password prefix'       => ['admin', 'hunter'];
        yield 'password with suffix'  => ['admin', 'hunter22'];
        yield 'user prefix'           => ['adm', 'hunter2'];
        yield 'case-changed user'     => ['Admin', 'hunter2'];
        yield 'case-changed password' => ['admin', 'Hunter2'];
        yield 'the hash as password'  => ['admin', '$2y$04$'];
    }

    /**
     * The password comparison runs even when the user name is wrong.
     *
     * `hash_equals() && password_verify()` leaked, as a pair, what neither leaks alone: bcrypt is
     * deliberately slow, so a wrong user name came back in microseconds while a right one paid the
     * full cost. That difference is measurable across a network, and it tells an attacker which
     * half of the credential they already hold — the half a brute-force attempt gets no other
     * feedback on.
     *
     * Measured rather than read off the source, because the property is behavioural. Cost 10 puts a
     * verify in the tens of milliseconds; the floor asserted here is a small fraction of that, and
     * a short-circuit would return in microseconds — so the gap is three orders of magnitude and
     * load can only push the measurement the safe way.
     *
     * @return void
     */
    public function testAWrongUserNameStillPaysForThePasswordCheck(): void
    {
        $file = $this->credentials('admin', 'hunter2', cost: 10);

        $start = hrtime(true);
        self::assertFalse(Auth::accepts(self::request('wrong-user-entirely', 'hunter2'), $file));
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        self::assertGreaterThan(
            1.0,
            $elapsed,
            'A wrong user name returned before bcrypt could have run — the comparisons are '
            . 'short-circuiting again, which makes the user name enumerable by timing.',
        );
    }

    /**
     * An unconfigured gate is closed, not open. This is the state a repository ships: its
     * `data/admin.php` is a placeholder whose `pass_hash` is empty, because the live credentials are
     * uploaded by hand.
     *
     * @return void
     */
    public function testAnEmptyHashAcceptsNobodyIncludingAnEmptyPassword(): void
    {
        $file = $this->credentials('admin', '');

        self::assertFalse(Auth::accepts(self::request('admin', ''), $file));
        self::assertFalse(Auth::accepts(self::request('admin', 'hunter2'), $file));
    }

    // ───────────────────────────── the admin gate ─────────────────────────────

    /**
     * @return void
     */
    public function testTheAdminGateLetsTheRightCredentialsThrough(): void
    {
        self::assertNull(
            Auth::adminGate(self::request('preview', 'hunter2'), $this->credentials('preview', 'hunter2')),
        );
    }

    /**
     * A gate that refuses answers with the challenge, in the app's own realm, and nothing else —
     * the prompt is the whole of what a visitor sees.
     *
     * @return void
     */
    public function testTheAdminGateRefusesTheWrongCredentialsWithTheAppsChallenge(): void
    {
        $request = self::request('preview', 'wrong');
        $refusal = Auth::adminGate($request, $this->credentials('preview', 'hunter2'));

        self::assertNotNull($refusal);

        $answer = $refusal->answer($request);

        self::assertSame(HttpStatusCode::Unauthorized, $answer->status());
        self::assertSame(
            new BasicChallenge(TestApp::current()->name())->render(),
            $answer->header(ResponseHeader::WwwAuthenticate)?->value->render(),
        );
        self::assertSame('', $answer->body());
    }

    // ───────────────────────────── PasswordHash ─────────────────────────────

    /**
     * @return void
     */
    public function testABcryptDigestVerifiesTheRightPasswordAndNothingElse(): void
    {
        $hash = self::hash('hunter2');

        self::assertTrue($hash->matches('hunter2'));
        self::assertFalse($hash->matches('hunter3'));
        self::assertFalse($hash->matches(''));
    }

    /**
     * The failure this class exists for: `password_verify()` answers false both for a wrong
     * password and for a digest that is not one, and those are opposite problems. A truncated paste
     * would otherwise present as a password that quietly stopped working.
     *
     * @param string $digest
     * @return void
     */
    #[DataProvider('nonDigestProvider')]
    public function testSomethingThatIsNotABcryptDigestIsRefusedWhereItIsWritten(string $digest): void
    {
        $this->expectException(InvalidValueException::class);

        new PasswordHash($digest);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonDigestProvider(): iterable
    {
        yield 'a plain password'  => ['hunter2'];
        yield 'the empty string'  => [''];
        yield 'a truncated paste' => ['$2y$12$.UgLy0HhsjKSHB.qMCnqRu6MewIN'];
        yield 'argon2i'           => [password_hash('x', PASSWORD_ARGON2I)];
        yield 'a bare md5'        => [md5('hunter2')];
    }

    /**
     * The empty string is the one non-digest that is not a mistake: it is how a credentials file
     * spells an unconfigured gate. Null, not an exception, and not a hash that accepts everything.
     *
     * @return void
     */
    public function testAnEmptyHashIsAnUnconfiguredGateRatherThanAMalformedOne(): void
    {
        self::assertNull(PasswordHash::configured(''));
        self::assertInstanceOf(PasswordHash::class, PasswordHash::configured(self::hash('hunter2')->digest()));
    }

    /**
     * The dummy a gate burns time against when there is nothing to compare with. It must be a real
     * digest — `password_verify()` on a malformed one returns immediately, which would defeat the
     * whole point — and it must match nothing.
     *
     * @return void
     */
    public function testTheUnmatchableDigestIsRealAndMatchesNothing(): void
    {
        $hash = PasswordHash::unmatchable();

        self::assertSame(PASSWORD_BCRYPT, password_get_info($hash->digest())['algo']);
        self::assertFalse($hash->matches(''));
        self::assertFalse($hash->matches('hunter2'));
        self::assertFalse($hash->matches($hash->digest()));
    }
}
