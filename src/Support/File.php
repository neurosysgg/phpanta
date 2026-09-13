<?php

declare(strict_types=1);

namespace Phpanta\Support;

/**
 * The File class. A path on disk, and the handful of things this codebase does with one.
 *
 * It exists because the same three lines were written in five places. `PrivacyController` says so
 * in its own docblock — *"`is_file()` first, the way every other data-file read on this site does
 * it — `Auth`, `ProfileRepository` and `StatsController` all check before reading"* — which is a
 * class waiting to be named.
 *
 * **It also fixes the bug that docblock describes.** `is_file()` guards a file that is not there
 * and does nothing about one that is there and unreadable: `file_get_contents()` then emits a
 * warning, and the response headers have already gone out, so on the live host that warning prints
 * into the page ahead of the doctype. {@link self::read()} suppresses it in one place and answers
 * `null`, which is the thing every caller was already checking for.
 *
 * **It deliberately cannot create a directory.** {@link self::write()} and {@link self::append()}
 * both fail on a path whose directory does not exist, and that is the correct behaviour rather than
 * an omission: the downloads log's directory is excluded from `deploy.sh`, and a write that could
 * create it would create it on the live server, where it then has to be deleted by hand. Creating a
 * directory is {@link Directory}'s to do and a caller's to ask for. See docs/history/types.md.
 *
 * Who reads what: the site **reads** and **appends** — it never writes a file and never deletes
 * one, which is a property of a read-only deployment rather than a gap. The tooling writes and
 * deletes. The tests build fixtures with {@link Directory}, and each one is a `File`.
 */
final readonly class File
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $path An absolute path. Nothing here resolves a relative one, because every
     *                     path in this codebase comes from {@link \NeuroSYS\Site} or from an
     *                     argv, and a path resolved against a working directory is a path that
     *                     means something different depending on where a command was run.
     */
    public function __construct(public string $path) {}

    /**
     * Whether there is a file here — not a directory, and not nothing.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * The file's contents, or null where there are none to be had.
     *
     * **One `null` for both failures, on purpose.** Absent and unreadable are different causes and
     * the same answer: this file did not tell us anything. It is the collapse a caller would
     * otherwise write out as `is_file($f) ? file_get_contents($f) ?: '' : ''`, minus the warning.
     *
     * **`$limit` bounds the read itself, because a bound applied after it is not a bound.**
     * {@link \Phpanta\Http\Request::body()} reads `php://input` through here, and left unbounded a
     * `file_get_contents()` pulls up to `post_max_size` — a php.ini value nobody in this repository
     * controls — into one string before the caller can weigh it. Given a limit, only that many
     * bytes are ever read, so the ceiling is the one the caller states rather than the one the SAPI
     * inherited. Null reads the file whole, which is every other caller: their paths come from
     * {@link \NeuroSYS\Site} and are as long as they are.
     *
     * @param int|null $limit The most bytes to read, or null for the whole file.
     * @return string|null
     */
    public function read(?int $limit = null): ?string
    {
        $contents = Diagnostics::muted(
            fn(): string|false => file_get_contents($this->path, false, null, 0, $limit),
        );

        return $contents === false ? null : $contents;
    }

    /**
     * The file's last $bytes bytes — all of it where it is shorter — or null where it cannot be read.
     *
     * {@link self::read()} from the other end, and bounded for the same reason. A log is read from
     * where it was last written to, and a whole-file read of one that has grown for a month pays
     * for every line in it to quote the last twenty — which is all
     * {@link \Phpanta\Service\Api\CapabilityErrors} does with it. The length is passed as well as
     * the offset, so a file that grows between the size and the read still yields no more than
     * $bytes.
     *
     * The first line of what comes back is usually cut through; which lines are whole is the
     * caller's to decide, because only the caller knows whether it read the file from its start.
     *
     * @param int $bytes The most bytes to read, counted back from the end.
     * @return string|null
     */
    public function tail(int $bytes): ?string
    {
        $offset   = max(0, $this->size() - $bytes);
        $contents = Diagnostics::muted(
            fn(): string|false => file_get_contents($this->path, false, null, $offset, $bytes),
        );

        return $contents === false ? null : $contents;
    }

    /**
     * The file's lines, without their trailing newlines, or none at all.
     *
     * @return list<string>
     */
    #[BareArray(
        'file() is the door and this is the doorway itself, the way glob() is behind '
        . 'Directory::files(). The difference is what comes through: a File is a type this '
        . 'codebase owns and a line of text is not, so there is nothing for a collection to say '
        . 'that a plain list of strings does not.',
    )]
    #[BareCall(
        'array_values',
        'file() is the door and this is the doorway, which the #[BareArray] above already says. '
        . 'array_values() is what makes the result a list rather than whatever keys the read left '
        . 'behind, and there is no collection on either side of it to ask instead.',
    )]
    public function lines(): array
    {
        $lines = Diagnostics::muted(fn(): array|false => file($this->path, FILE_IGNORE_NEW_LINES));

        return $lines === false ? [] : array_values($lines);
    }

    /**
     * Appends one line, under an exclusive lock.
     *
     * The lock is the reason this is not `file_put_contents(..., FILE_APPEND)`: two requests
     * finishing a download at the same moment would otherwise interleave halfway through a line,
     * and a log whose lines cannot be trusted to be whole is worse than no log.
     *
     * @param string $line Written with a newline after it.
     * @return bool False if the file could not be opened or the lock not taken — which the one
     *              caller treats as "nothing was logged" and carries on, because a download is not
     *              worth failing over a log.
     */
    public function append(string $line): bool
    {
        // `mixed` rather than `resource|false`: a resource is the one thing PHP hands back that
        // cannot be written as a type declaration.
        $handle = Diagnostics::muted(fn(): mixed => fopen($this->path, 'ab'));

        if ($handle === false) {
            return false;
        }

        $written = false;

        if (flock($handle, LOCK_EX)) {
            $written = fwrite($handle, $line . "\n") !== false;
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return $written;
    }

    /**
     * Replaces the file's contents.
     *
     * **Written beside and renamed into place**, because `rename()` is atomic within a filesystem
     * and a half-written file is the failure worth designing against here: the one thing this
     * writes on a real machine is the SoundCloud refresh token, which is single-use, and a reader
     * that finds half of one has lost it. A reader sees the old contents or the new ones.
     *
     * @param string   $contents
     * @param int|null $mode Applied to the temporary file *before a byte of $contents is in it*, so
     *                       the contents are never readable at the default mode. Null leaves it to
     *                       the umask.
     * @return bool
     */
    public function write(string $contents, ?int $mode = null): bool
    {
        $temporary = $this->path . '.' . getmypid() . '.tmp';

        // Created empty, then narrowed, then filled — and the order is the whole point rather than
        // a style. `file_put_contents()` creates at `0666 & ~umask`, so writing first and chmod-ing
        // after put the contents on disk at 0644 under the usual umask and narrowed them a
        // statement later: world-readable for exactly as long as the two calls took. The one thing
        // this writes on a real machine is a single-use refresh token, so that window is the
        // failure this argument exists to prevent. An empty file at the default mode says nothing
        // to anybody, which is why creating one first costs nothing.
        if (Diagnostics::muted(static fn(): bool => touch($temporary)) === false) {
            return false;
        }

        if ($mode !== null && !Diagnostics::muted(static fn(): bool => chmod($temporary, $mode))) {
            Diagnostics::muted(static fn(): bool => unlink($temporary));

            return false;
        }

        if (Diagnostics::muted(static fn(): int|false => file_put_contents($temporary, $contents)) === false) {
            Diagnostics::muted(static fn(): bool => unlink($temporary));

            return false;
        }

        if (!Diagnostics::muted(fn(): bool => rename($temporary, $this->path))) {
            Diagnostics::muted(static fn(): bool => unlink($temporary));

            return false;
        }

        return true;
    }

    /**
     * Removes the file, if it is there.
     *
     * @return bool False if there was something here and it could not be removed. A file that was
     *              never there is a success: the postcondition is what is being asked for.
     */
    public function delete(): bool
    {
        return !$this->exists() || Diagnostics::muted(fn(): bool => unlink($this->path));
    }

    /**
     * The file's name, without the directory.
     *
     * @return string
     */
    public function name(): string
    {
        return basename($this->path);
    }

    /**
     * The file's extension, lower-cased and without the dot, or `''` where it has none.
     *
     * Lower-cased because every caller compares it against something lower-case — a
     * {@link \NeuroSYS\Model\ReleaseFormat} value, a list of image extensions — and a `.FLAC` off a
     * camera or a Windows share is the same format as a `.flac`.
     *
     * @return string
     */
    public function extension(): string
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    /**
     * The file's size in bytes, or 0 where there is no file.
     *
     * @return int
     */
    public function size(): int
    {
        return $this->exists() ? (int) Diagnostics::muted(fn(): int|false => filesize($this->path)) : 0;
    }

    /**
     * The directory this file sits in.
     *
     * @return Directory
     */
    public function directory(): Directory
    {
        return new Directory(dirname($this->path));
    }
}
