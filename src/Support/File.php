<?php

declare(strict_types=1);

namespace Phpanta\Support;

use NoDiscard;
use Phpanta\Exception\InvalidValueException;

/**
 * The File class. A path on disk, and the handful of things an app does with one.
 *
 * It exists because the same three lines — `is_file()`, then `file_get_contents()`, then a check of
 * what came back — were written wherever a data file was read, which is a class waiting to be named.
 *
 * **It also fixes the bug those lines have.** `is_file()` guards a file that is not there
 * and does nothing about one that is there and unreadable: `file_get_contents()` then emits a
 * warning, and the response headers have already gone out, so on a host that buffers no output that
 * warning prints into the page ahead of the doctype. {@link self::read()} suppresses it in one place and answers
 * `null`, which is the thing every caller was already checking for.
 *
 * **It deliberately cannot create a directory.** {@link self::write()} and {@link self::append()}
 * both fail on a path whose directory does not exist, and that is the correct behaviour rather than
 * an omission: a log's directory is often left out of a deploy on purpose, and a write that could
 * create it would create it on the live server, where it then has to be deleted by hand. Creating a
 * directory is {@link Directory}'s to do and a caller's to ask for. See docs/history/types.md.
 *
 * Who reads what: an app **reads** and **appends**, and one that takes uploads keeps them — see
 * {@link \Phpanta\Http\Upload::keepAs()}, which names its temporary file here. A read-only
 * deployment never writes a file and never deletes one. The tooling writes and deletes. The tests
 * build fixtures with {@link Directory}, and each one is a `File`.
 */
final readonly class File
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $path An absolute path. Nothing here resolves a relative one, because every
     *                     path comes from the app — {@link \Phpanta\App::dataFile()} and its
     *                     siblings — or from an argv, and a path resolved against a working
     *                     directory is a path that means something different depending on where
     *                     a command was run.
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
     * `file_get_contents()` pulls up to `post_max_size` — a php.ini value the app does not
     * control — into one string before the caller can weigh it. Given a limit, only that many
     * bytes are ever read, so the ceiling is the one the caller states rather than the one the SAPI
     * inherited. Null reads the file whole, which is every other caller: their paths come from
     * the app and are as long as they are.
     *
     * A directory has no contents to read, and is null too: PHP opens one, and reads `''` from it.
     *
     * @param int|null $limit The most bytes to read, or null for the whole file.
     * @return string|null
     * @throws InvalidValueException if $limit is less than nothing — PHP's own answer is a ValueError.
     */
    public function read(?int $limit = null): ?string
    {
        if ($limit !== null && $limit < 0) {
            throw new InvalidValueException(sprintf('A read of %d bytes asks for less than nothing.', $limit));
        }

        // Asked inside the muted call, because a stream such as `php://input` may not answer a stat.
        $contents = Diagnostics::muted(
            fn(): string|false => is_dir($this->path)
                ? false
                : file_get_contents($this->path, false, null, 0, $limit),
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
     * @throws InvalidValueException if $bytes is less than nothing, as {@link self::read()} refuses.
     */
    public function tail(int $bytes): ?string
    {
        if ($bytes < 0) {
            throw new InvalidValueException(sprintf('A tail of %d bytes asks for less than nothing.', $bytes));
        }

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
        . 'Directory::files(). The difference is what comes through: a File is a type the '
        . 'framework owns and a line of text is not, so there is nothing for a collection to say '
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
        $lines = Diagnostics::muted(
            #[BareArray('file() answers in an array, or false: this is the door it comes through')]
            fn(): array|false => file($this->path, FILE_IGNORE_NEW_LINES),
        );

        return $lines === false ? [] : array_values($lines);
    }

    /**
     * Appends one line, under an exclusive lock.
     *
     * The lock is the reason this is not `file_put_contents(..., FILE_APPEND)`: two requests
     * logging at the same moment would otherwise interleave halfway through a line,
     * and a log whose lines cannot be trusted to be whole is worse than no log.
     *
     * @param string $line Written with a newline after it.
     * @return bool False if the file could not be opened or the lock not taken — which a caller
     *              logging beside a response treats as "nothing was logged" and carries on, because
     *              a response is not worth failing over a log.
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
     * and a half-written file is the failure worth designing against here: what this is for is a
     * secret such as a single-use refresh token, and a reader that finds half of one has lost it. A
     * reader sees the old contents or the new ones.
     *
     * @param string   $contents
     * @param int|null $mode Applied to the temporary file *before a byte of $contents is in it*, so
     *                       the contents are never readable at the default mode. Null keeps the
     *                       mode of the file being replaced, where there is one, and leaves a new
     *                       file to the umask: a rewrite that put a file back to the umask's mode
     *                       would widen one an earlier write had narrowed.
     * @return bool
     */
    public function write(string $contents, ?int $mode = null): bool
    {
        $temporary = $this->temporarySibling()->path;
        $mode    ??= $this->permissions();

        // Created empty, then narrowed, then filled — and the order is the whole point rather than
        // a style. `file_put_contents()` creates at `0666 & ~umask`, so writing first and chmod-ing
        // after put the contents on disk at 0644 under the usual umask and narrowed them a
        // statement later: world-readable for exactly as long as the two calls took. What this
        // writes is a secret such as a single-use refresh token, so that window is the
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
     * A file beside this one that nothing else is named — the name to fill and then rename onto this
     * one, so a reader sees the old file or the new one, as {@link self::write()} does.
     *
     * Beside it, because `rename()` is atomic only within a filesystem. Random as well as
     * per-process: php-fpm serves request after request from one PID, so the PID alone names the
     * same temporary file for two writes in flight at once.
     *
     * @return self
     */
    #[NoDiscard('temporarySibling() only names a file; a call whose result goes nowhere named nothing')]
    public function temporarySibling(): self
    {
        return new self($this->path . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp');
    }

    /**
     * The permission bits of the file here, or null where there is none to keep.
     *
     * Public for a file written somewhere else before it is moved here — a push stages a file beside
     * the deployment and renames it into place, and the staged file has to be written at the mode of
     * the one it replaces, as {@link self::write()} would have kept it.
     *
     * @return int|null
     */
    public function permissions(): ?int
    {
        $permissions = $this->exists() ? Diagnostics::muted(fn(): int|false => fileperms($this->path)) : false;

        return $permissions === false ? null : $permissions & 0o7777;
    }

    /**
     * Renames this file onto $target, replacing whatever file is there, in one step.
     *
     * Atomic within a filesystem, which is what {@link self::write()} relies on for its temporary
     * file and what a staged push relies on for a whole tree: a reader sees the old file or the new
     * one. Across filesystems PHP falls back to a copy, which is neither atomic nor what a caller
     * staging beside the target meant — so stage on the same one.
     *
     * @param File $target
     * @return bool
     */
    public function moveOnto(File $target): bool
    {
        return Diagnostics::muted(fn(): bool => rename($this->path, $target->path));
    }

    /**
     * Removes the file, if it is there.
     *
     * Asked of the name, not of what it points at: a link whose target is gone is still a link, and
     * removing it is removing the link. A directory is something here this does not remove —
     * {@link Directory::remove()} does — so it answers false rather than a success over a directory
     * still standing.
     *
     * @return bool False if there was something here and it could not be removed, or it is a
     *              directory. A file that was never there is a success: the postcondition is what is
     *              being asked for.
     */
    public function delete(): bool
    {
        if (is_link($this->path)) {
            return Diagnostics::muted(fn(): bool => unlink($this->path));
        }

        if (is_dir($this->path)) {
            return false;
        }

        return !file_exists($this->path) || Diagnostics::muted(fn(): bool => unlink($this->path));
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
     * format enum's value, a list of image extensions — and a `.FLAC` off a
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
