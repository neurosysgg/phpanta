<?php

declare(strict_types=1);

namespace Phpanta\Support;

use NoDiscard;

/**
 * The FileLock class. An exclusive `flock()` on one file, held until it is released.
 *
 * **Non-blocking, deliberately.** Its caller is a write through `/api`, and a second write arriving
 * while the first is still running has nothing worth waiting for: it was minted against the tree as
 * it stood before the first, and queueing it would apply it on top of a push it never saw. Answering
 * at once lets whoever sent it decide.
 *
 * **A dropped lock is a released one** with no destructor to say so: the handle is freed with the
 * object, closing a handle releases its `flock()`, and a process that dies mid-write takes its
 * handles with it. {@link self::release()} is for letting go before the object would.
 *
 * The lock file is created where it is missing and never deleted. Deleting it would open a window in
 * which two processes each hold a lock on a different inode under the same name, which is two locks
 * and no exclusion.
 */
#[BareString(
    'c',
    'fopen()\'s mode: create where missing, never truncate — the one mode that neither empties a lock '
    . 'file another process holds nor fails on the first run. It is a one-letter word in other '
    . 'vocabularies too (date()\'s ISO 8601 format), which is a coincidence rather than a shared name.',
)]
final class FileLock
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param mixed $handle The open handle the lock is held on, or null once released. `mixed`
     *                      rather than a resource, because a resource is the one thing PHP hands back
     *                      that cannot be written as a type — the note {@link File::append()} carries.
     */
    private function __construct(private mixed $handle) {}

    /**
     * Takes the lock on $file, or answers null where another process holds it or the file cannot be
     * opened at all.
     *
     * The two failures collapse to one answer because they have one consequence: nothing may run
     * that the lock was meant to guard. A lock file that cannot be opened sits beside a record that
     * cannot be written either, so the caller finds out the same way whichever it was.
     *
     * @param File $file
     * @return self|null
     */
    #[NoDiscard('a lock nobody holds is released the moment it is taken')]
    public static function exclusive(File $file): ?self
    {
        $handle = Diagnostics::muted(static fn(): mixed => fopen($file->path, 'c'));

        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return new self($handle);
    }

    /**
     * Lets the lock go. Releasing twice is releasing once.
     *
     * @return void
     */
    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        $this->handle = null;
    }
}
