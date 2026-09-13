<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Exception\InvalidValueException;
use Phpanta\Exception\ThrottleException;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\FileLock;
use Phpanta\Support\Throttle;
use Phpanta\Support\ThrottleVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * At most so many attempts per key within a window that slides, remembered on disk — and a limiter
 * that cannot remember refuses to count rather than letting everything through.
 */
#[CoversClass(Throttle::class)]
#[CoversClass(ThrottleVerdict::class)]
#[CoversClass(FileLock::class)]
#[CoversClass(File::class)]
#[CoversClass(Directory::class)]
#[CoversClass(Diagnostics::class)]
final class ThrottleTest extends TestCase
{
    /** A directory of this test's own, removed afterwards. */
    private Directory $directory;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = Directory::temporary('phpanta-throttle-');
    }

    /**
     * Put back whatever a test narrowed or planted, then remove the directory.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        chmod($this->directory->path, 0o700);

        foreach (scandir($this->directory->path) ?: [] as $name) {
            $path = $this->directory->path . '/' . $name;

            if ($name === '.' || $name === '..') {
                continue;
            }

            if (is_dir($path)) {
                rmdir($path);
            } else {
                chmod($path, 0o600);
            }
        }

        $this->directory->remove();
    }

    /**
     * Three attempts a minute unless a test says otherwise, in this test's directory.
     *
     * @param int $limit
     * @param int $window
     * @return Throttle
     */
    private function throttle(int $limit = 3, int $window = 60): Throttle
    {
        return new Throttle($this->directory, $limit, $window);
    }

    /**
     * @return void
     */
    public function testUnderTheLimitEveryAttemptIsAllowed(): void
    {
        $throttle = $this->throttle();

        foreach ([1000, 1010, 1020] as $now) {
            $verdict = $throttle->attempt('203.0.113.9', $now);

            self::assertTrue($verdict->allowed(), "attempt at $now");
            self::assertSame(0, $verdict->retryAfter());
        }
    }

    /**
     * At the limit an attempt is refused until the oldest counted one leaves the window.
     *
     * @return void
     */
    public function testAtTheLimitAnAttemptIsRefusedUntilTheOldestLeavesTheWindow(): void
    {
        $throttle = $this->throttle();

        foreach ([1000, 1010, 1020] as $now) {
            self::assertTrue($throttle->attempt('k', $now)->allowed());
        }

        $refused = $throttle->attempt('k', 1030);

        self::assertFalse($refused->allowed());
        self::assertSame(30, $refused->retryAfter(), '1000 leaves a 60-second window at 1060');
    }

    /**
     * The window slides: the oldest attempt leaving it makes room for exactly one more.
     *
     * @return void
     */
    public function testTheWindowSlides(): void
    {
        $throttle = $this->throttle();

        foreach ([1000, 1010, 1020] as $now) {
            self::assertTrue($throttle->attempt('k', $now)->allowed());
        }

        self::assertFalse($throttle->attempt('k', 1059)->allowed(), 'still inside the window');
        self::assertTrue($throttle->attempt('k', 1060)->allowed(), '1000 has left the window');

        $refused = $throttle->attempt('k', 1061);
        self::assertFalse($refused->allowed());
        self::assertSame(9, $refused->retryAfter(), '1010 is now the oldest, and leaves at 1070');

        self::assertTrue($throttle->attempt('k', 1070)->allowed());
    }

    /**
     * A refused attempt is not counted, so knocking while refused does not push the wait further out.
     *
     * @return void
     */
    public function testARefusedAttemptIsNotCounted(): void
    {
        $throttle = $this->throttle(limit: 1);

        self::assertTrue($throttle->attempt('k', 1000)->allowed());

        foreach ([1010, 1020, 1030, 1040, 1050, 1059] as $now) {
            self::assertFalse($throttle->attempt('k', $now)->allowed());
        }

        self::assertTrue($throttle->attempt('k', 1060)->allowed());
    }

    /**
     * @return void
     */
    public function testKeysAreCountedApart(): void
    {
        $throttle = $this->throttle(limit: 1);

        self::assertTrue($throttle->attempt('203.0.113.9', 1000)->allowed());
        self::assertFalse($throttle->attempt('203.0.113.9', 1001)->allowed());
        self::assertTrue($throttle->attempt('198.51.100.7', 1001)->allowed());
    }

    /**
     * A successful login forgets the failures before it — and only its own.
     *
     * @return void
     */
    public function testClearForgetsOneKey(): void
    {
        $throttle = $this->throttle(limit: 1);

        self::assertTrue($throttle->attempt('k', 1000)->allowed());
        self::assertTrue($throttle->attempt('other', 1000)->allowed());

        self::assertTrue($throttle->clear('k'));
        self::assertTrue($throttle->clear('never-seen'), 'nothing to forget is forgotten');

        self::assertTrue($throttle->attempt('k', 1001)->allowed());
        self::assertFalse($throttle->attempt('other', 1001)->allowed());
    }

    /**
     * What is left, asked without spending any of it.
     *
     * @return void
     */
    public function testRemainingCountsWithoutAttempting(): void
    {
        $throttle = $this->throttle();

        self::assertSame(3, $throttle->remaining('k', 1000));
        self::assertSame(3, $throttle->remaining('k', 1000), 'asking is not attempting');

        (void) $throttle->attempt('k', 1000);
        self::assertSame(2, $throttle->remaining('k', 1000));

        (void) $throttle->attempt('k', 1010);
        (void) $throttle->attempt('k', 1020);
        (void) $throttle->attempt('k', 1030);
        self::assertSame(0, $throttle->remaining('k', 1030), 'never below none');

        self::assertSame(1, $throttle->remaining('k', 1060), 'the window slid past one');
        self::assertSame(3, $throttle->remaining('k', 2000), 'and past all of them');
    }

    /**
     * A limit lowered under a record written at the old one waits for enough of it to leave.
     *
     * @return void
     */
    public function testALoweredLimitWaitsForEnoughToLeave(): void
    {
        $generous = $this->throttle(limit: 3);

        foreach ([1000, 1010, 1020] as $now) {
            (void) $generous->attempt('k', $now);
        }

        $refused = $this->throttle(limit: 1)->attempt('k', 1030);

        self::assertFalse($refused->allowed());
        self::assertSame(50, $refused->retryAfter(), 'all three must go, the last of them at 1080');
    }

    /**
     * A key is hashed into a name, so one that reads as a path is just a key.
     *
     * @return void
     */
    public function testAKeyThatReadsAsAPathIsJustAKey(): void
    {
        $throttle = $this->throttle(limit: 1);
        $outside  = $this->directory->path . '-escaped';
        $escape   = '../../../../' . basename($outside);

        self::assertTrue($throttle->attempt($escape, 1000)->allowed());
        self::assertTrue($throttle->attempt('/etc/passwd', 1000)->allowed());
        self::assertFalse($throttle->attempt($escape, 1001)->allowed(), 'and it is counted like any other');

        self::assertFileDoesNotExist($outside);

        $names = [];
        foreach ($this->directory->files() as $file) {
            $names[] = $file->name();
        }
        sort($names);

        self::assertCount(3, $names);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $names[0]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $names[1]);
        self::assertSame('throttle.lock', $names[2]);
    }

    /**
     * A missing directory is refused by every question, naming the directory — never a throttle that
     * counts nothing and allows everything.
     *
     * @return void
     */
    public function testAMissingDirectoryIsRefusedLoudly(): void
    {
        $missing   = $this->directory->directory('not-there');
        $throttle  = new Throttle($missing, 3, 60);
        $questions = [
            'attempt'   => static fn(): mixed => $throttle->attempt('k', 1000),
            'remaining' => static fn(): mixed => $throttle->remaining('k', 1000),
            'clear'     => static fn(): mixed => $throttle->clear('k'),
        ];

        foreach ($questions as $question => $ask) {
            try {
                $ask();
                self::fail("$question counted against a directory that is not there");
            } catch (ThrottleException $e) {
                self::assertStringContainsString($missing->path, $e->getMessage());
            }
        }
    }

    /**
     * A directory PHP cannot write into is refused the same way.
     *
     * @return void
     */
    public function testAnUnwritableDirectoryIsRefused(): void
    {
        chmod($this->directory->path, 0o500);

        if (is_writable($this->directory->path)) {
            self::markTestSkipped('running as a user no permission stops');
        }

        $this->expectException(ThrottleException::class);
        $this->expectExceptionMessage($this->directory->path);

        (void) $this->throttle()->attempt('k', 1000);
    }

    /**
     * A record that is there and cannot be read is refused, not read as no attempts.
     *
     * @return void
     */
    public function testAnUnreadableRecordIsRefused(): void
    {
        $throttle = $this->throttle();
        (void) $throttle->attempt('k', 1000);

        $record = $this->directory->file(hash('sha3-256', 'k'));
        chmod($record->path, 0o000);

        if ($record->read() !== null) {
            self::markTestSkipped('running as a user no permission stops');
        }

        $this->expectException(ThrottleException::class);

        (void) $throttle->attempt('k', 1001);
    }

    /**
     * A record that cannot be written is refused, and the attempt is not reported as counted.
     *
     * @return void
     */
    public function testARecordThatCannotBeWrittenIsRefused(): void
    {
        // A directory where the record would go: nothing can be renamed over it.
        mkdir($this->directory->path . '/' . hash('sha3-256', 'k'));

        $this->expectException(ThrottleException::class);
        $this->expectExceptionMessage('could not be written');

        (void) $this->throttle()->attempt('k', 1000);
    }

    /**
     * A lock that cannot be taken is refused.
     *
     * @return void
     */
    public function testALockThatCannotBeTakenIsRefused(): void
    {
        mkdir($this->directory->path . '/throttle.lock');

        $this->expectException(ThrottleException::class);
        $this->expectExceptionMessage('lock');

        (void) $this->throttle()->attempt('k', 1000);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function nonsenseProvider(): iterable
    {
        yield 'no attempts at all' => [0, 60];
        yield 'a negative limit'   => [-1, 60];
        yield 'no time at all'     => [3, 0];
    }

    /**
     * A throttle that could never allow anything, or whose window is no time, is refused where it is
     * written.
     *
     * @param int $limit
     * @param int $window
     * @return void
     */
    #[DataProvider('nonsenseProvider')]
    public function testANonsenseLimitIsRefused(int $limit, int $window): void
    {
        $this->expectException(InvalidValueException::class);

        new Throttle($this->directory, $limit, $window);
    }

    /**
     * The waiting lock is the same lock the refusing one asks for.
     *
     * @return void
     */
    public function testTheWaitingLockExcludesLikeTheOther(): void
    {
        $file = $this->directory->file('x.lock');

        $held = FileLock::waitFor($file);
        self::assertInstanceOf(FileLock::class, $held);
        self::assertNull(FileLock::exclusive($file), 'a held lock was taken a second time');

        $held->release();

        $again = FileLock::waitFor($file);
        self::assertInstanceOf(FileLock::class, $again);
        $again->release();

        self::assertNull(FileLock::waitFor($this->directory->file('no-such-directory/x.lock')));
    }
}
