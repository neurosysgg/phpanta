<?php

declare(strict_types=1);

namespace Phpanta\Support;

use NoDiscard;
use Phpanta\Exception\InvalidValueException;
use Phpanta\Exception\ThrottleException;

/**
 * The Throttle class. At most so many attempts per key within a window of so many seconds, and the
 * window slides.
 *
 * ```php
 * $throttle = new Throttle($app->data()->directory('throttle'), limit: 5, window: 900);
 *
 * if (!$throttle->attempt($request->remoteAddress())->allowed()) { … }
 * ```
 *
 * **A sliding log, not a fixed bucket.** Each key's record is the times of the attempts it was let
 * through, and an attempt is allowed while fewer than the limit fall inside the last `window`
 * seconds. A fixed bucket — a counter reset on the minute — lets twice the limit through across a
 * boundary, the last second of one bucket and the first of the next; a log has no boundary. It
 * costs one line per allowed attempt, and never more than `limit` lines, because a write keeps only
 * what is still inside the window.
 *
 * **A refused attempt is not recorded.** The limit is on what gets through, so a client that keeps
 * knocking while refused is let in again when its oldest counted attempt leaves the window — not
 * pushed further out by every knock, which would turn a limit into a lockout that a patient
 * attacker could hold over somebody else's key for ever.
 *
 * **On disk, one file per key, named by the key's SHA3-256.** Every PHP process serving the app
 * counts the same attempts, which a static or an APCu counter would not promise on a host that
 * runs several. Hashing is what makes any string a safe key: `../../etc/passwd` is a digest like
 * any other, so no key names a path, and an address, a user name or both joined are equally
 * welcome. A digest no attacker can steer, because a key whose record landed on somebody else's
 * would spend that somebody's allowance. A record is written beside itself and renamed into place
 * ({@link File::write()}), so a read that takes no lock still sees a whole one.
 *
 * **One lock for the directory, held around every read-modify-write**, so two requests from one key
 * cannot both read four attempts and both write five. One lock rather than one per key because a
 * lock file is never deleted ({@link FileLock} says why) and a per-key one would be a second file
 * for every address ever seen that nothing could clear; the critical section is one small read and
 * one small write, so the requests it serialises wait for microseconds. It is the waiting lock,
 * {@link FileLock::waitFor()}, because a request that found it held and gave up would be refused
 * for a limit it had not reached.
 *
 * **It fails closed and loudly.** A directory that is missing or not writable, a lock that cannot be
 * taken, a record that cannot be read or written: each is a {@link ThrottleException} naming the
 * directory, never an attempt allowed because nothing could be counted. The directory is the
 * caller's to create ({@link Directory::create()}), and a deploy that leaves it out is told so on
 * the first request rather than finding out from an attack.
 *
 * What it does not do is forget a key that never comes back: a record outlives its window until the
 * key is attempted or cleared again. Each is a few dozen bytes, and the files are the directory's
 * owner's to sweep.
 */
final readonly class Throttle
{
    /**
     * The one lock file every record in the directory is written under. Not a digest, so no key's
     * record can ever be named the same.
     */
    private const string LOCK = 'throttle.lock';

    /**
     * Constructs an instance of {@link self}.
     *
     * Nothing is touched on disk here, so constructing one in {@link \Phpanta\App::layers()} on
     * every request costs nothing until it is asked.
     *
     * @param Directory $directory Where the records live. It must exist and be writable by PHP
     *                             when the throttle is first asked; nothing here creates it.
     * @param int       $limit     The most attempts a key may make within the window; at least 1.
     * @param int       $window    The window, in seconds; at least 1.
     *
     * @throws InvalidValueException if $limit or $window is below 1 — a throttle that could never
     *                               allow anything, or whose window is no time at all.
     */
    public function __construct(private Directory $directory, private int $limit, private int $window)
    {
        if ($limit < 1 || $window < 1) {
            throw new InvalidValueException(sprintf(
                'A Throttle needs a limit and a window of at least 1, got %d attempts per %d seconds.',
                $limit,
                $window,
            ));
        }
    }

    /**
     * Counts an attempt by $key if it is allowed, and answers whether it was.
     *
     * @param string   $key Whatever identifies the attempter — an address, a user name. Any string.
     * @param int|null $now The time of the attempt, as a Unix timestamp; null for now. A test seam:
     *                      production never passes it.
     * @return ThrottleVerdict Allowed, or refused and how many seconds until an attempt would not be.
     *
     * @throws ThrottleException if the attempt cannot be counted — see the class.
     */
    #[NoDiscard('the verdict is the whole point of attempting, and a dropped one lets the attempt through')]
    public function attempt(string $key, ?int $now = null): ThrottleVerdict
    {
        $now  ??= time();
        $record = $this->record($key);
        $lock   = $this->lock();

        try {
            $times = $this->times($record, $now);
            $count = count($times);

            if ($count >= $this->limit) {
                // Enough of the oldest must leave the window for one more to fit. That is the oldest
                // alone when the record is at the limit; more when the limit was lowered under a
                // record written at the old one.
                return ThrottleVerdict::refuse($times[$count - $this->limit] + $this->window - $now);
            }

            $times[] = $now;

            if (!$record->write(implode("\n", $times) . "\n")) {
                throw new ThrottleException(sprintf(
                    'A throttle record in %s could not be written, so the attempt was not counted.',
                    $this->directory->path,
                ));
            }

            return ThrottleVerdict::allow();
        } finally {
            $lock->release();
        }
    }

    /**
     * How many more attempts $key may make now, without making one.
     *
     * Read without the lock: a record is replaced whole, so this sees one state or the next, and an
     * answer that is a moment old is all a question like this can have anyway.
     *
     * @param string   $key
     * @param int|null $now As for {@link self::attempt()}.
     * @return int Between 0 and the limit.
     *
     * @throws ThrottleException if the directory is missing or not writable, or the record cannot
     *                           be read — the same refusal an attempt would meet.
     */
    #[NoDiscard('a count nobody reads is a file read for nothing')]
    public function remaining(string $key, ?int $now = null): int
    {
        $this->ensureWritable();

        return max(0, $this->limit - count($this->times($this->record($key), $now ?? time())));
    }

    /**
     * Forgets every attempt by $key — a successful login forgets the failures before it.
     *
     * @param string $key
     * @return bool False where the record was there and could not be removed. Nothing throws for
     *              that, because the key then stays counted, which is the closed direction: a
     *              visitor waits out a window they need not have.
     *
     * @throws ThrottleException if the directory is missing or not writable, or the lock cannot be
     *                           taken.
     */
    public function clear(string $key): bool
    {
        $lock = $this->lock();

        try {
            return $this->record($key)->delete();
        } finally {
            $lock->release();
        }
    }

    /**
     * The file $key's attempts are remembered in.
     *
     * @param string $key
     * @return File
     */
    private function record(string $key): File
    {
        return $this->directory->file(hash('sha3-256', $key));
    }

    /**
     * The directory's lock, held.
     *
     * @return FileLock
     *
     * @throws ThrottleException if the directory is not there to hold one, or the lock file cannot be
     *                           opened.
     */
    private function lock(): FileLock
    {
        $this->ensureWritable();

        return FileLock::waitFor($this->directory->file(self::LOCK)) ?? throw new ThrottleException(sprintf(
            'The throttle lock in %s could not be taken, so no attempt can be counted.',
            $this->directory->path,
        ));
    }

    /**
     * Refuses a directory attempts cannot be written into.
     *
     * @return void
     *
     * @throws ThrottleException if it is missing or not writable.
     */
    private function ensureWritable(): void
    {
        if (!$this->directory->isWritable()) {
            throw new ThrottleException(sprintf(
                'The throttle directory %s is missing or not writable by PHP, so no attempt can be '
                . 'counted. Create it, writable by the user PHP runs as.',
                $this->directory->path,
            ));
        }
    }

    /**
     * The times of $record's attempts still inside the window at $now, oldest first.
     *
     * A line that is not a timestamp is passed over rather than refused: only this class writes a
     * record, and whole, so there is nothing a stray line could be but a record from a different
     * version of it.
     *
     * @param File $record
     * @param int  $now
     * @return list<int>
     *
     * @throws ThrottleException if the record is there and cannot be read — read as empty, it would
     *                           forget every attempt in it.
     */
    #[BareArray(
        'the timestamps of one record, read, counted and written back within one method call under one '
        . 'lock, and never handed to anything outside this class — a list of ints on its way between '
        . 'a file and the same file.',
    )]
    private function times(File $record, int $now): array
    {
        if (!$record->exists()) {
            return [];
        }

        $contents = $record->read() ?? throw new ThrottleException(sprintf(
            'A throttle record in %s is there and cannot be read, so the attempts in it cannot be counted.',
            $this->directory->path,
        ));

        $times = [];

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^\d+\z/', $line) === 1 && (int) $line > $now - $this->window) {
                $times[] = (int) $line;
            }
        }

        sort($times);

        return $times;
    }
}
