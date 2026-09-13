<?php

declare(strict_types=1);

namespace Phpanta\Support;

/**
 * The Directory class. The other half of {@link File}: a path that holds files.
 *
 * An app asks it for exactly one thing — {@link \Phpanta\App::dataFile()} resolves a file
 * inside `data/` through {@link self::file()}, so the one derivation of that path stays one
 * derivation and now hands back something typed. Everything else here is read by the tooling, which
 * lists and creates directories, and by the tests, which build fixtures out of them.
 *
 * **It does not recurse, and that is a decision.** {@link self::files()} lists this directory and
 * not the tree under it, and {@link self::remove()} takes away the files it holds and then itself,
 * refusing rather than descending. A fixture is one level deep and the `data/` directory is one
 * level deep; a recursive delete is a thing nothing here needs, and a thing worth not having lying
 * around where somebody could reach for it with a path they had not checked.
 */
final readonly class Directory
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $path An absolute path, for the reason {@link File} gives.
     */
    public function __construct(public string $path) {}

    /**
     * A new directory under the system's temporary one, created and ready to write into.
     *
     * Here rather than in a test helper because a fixture directory is what four test files were
     * each opening with three lines of their own — and because the name matters: it is random, so
     * two tests running at once cannot collide, and it is prefixed, so anything left behind by a
     * test that died says which suite left it.
     *
     * @param string $prefix Prepended to the random part, e.g. `update-test-`.
     * @return self
     */
    public static function temporary(string $prefix): self
    {
        $directory = new self(sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6)));

        $directory->create(0o700);

        return $directory;
    }

    /**
     * Whether there is a directory here.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return is_dir($this->path);
    }

    /**
     * Whether there is a directory here that this process can create files in.
     *
     * Asked of the directory rather than of a file in it, because the file PHP logs into need not
     * exist yet — it is opened for appending and created on the first diagnostic — and whether it
     * *can* be created is a question of the directory's permissions, not of the file's.
     *
     * @return bool
     */
    public function isWritable(): bool
    {
        return $this->exists() && is_writable($this->path);
    }

    /**
     * A file in this directory, by name. The file need not exist.
     *
     * @param string $name A file name, or a path relative to this directory — `logs/app.log`.
     * @return File
     */
    public function file(string $name): File
    {
        return new File($this->path . '/' . $name);
    }

    /**
     * A directory inside this one. It need not exist.
     *
     * @param string $name
     * @return self
     */
    public function directory(string $name): self
    {
        return new self($this->path . '/' . $name);
    }

    /**
     * The files directly in this directory whose names match $pattern, in the order the filesystem
     * gives them.
     *
     * Directories are left out: every caller wants files, and one that had to check each entry
     * would be doing by hand what this exists to have done once.
     *
     * **Listed with `scandir()` and matched with `fnmatch()`, never `glob()`.** `glob()` reads the
     * whole path as a pattern, so a directory whose own name holds a `[` or a `*` — a folder called
     * `site [draft]` — was read as a pattern too, and listed nothing. Only a name is matched here.
     * `FNM_PERIOD` keeps the one rule of `glob()`'s worth keeping: a leading dot is matched only by a
     * pattern that writes one, so a folder's `.DS_Store` is not a file anybody asked for.
     *
     * What crosses the boundary is a {@link Collection}, which is what lets a caller ask `->first()`
     * or `->where()` of a directory without unwrapping it first.
     *
     * **`settled()`, because a listing is a snapshot and not a live query.** `where()` is lazy and
     * `exists()` is a `stat()`, so left pending this would re-read the filesystem on every
     * materialisation — two questions of the same object could answer differently. The directory has
     * already been read once here; settling keeps the whole listing one answer taken at one moment,
     * which is what every caller has always read it as.
     *
     * @param string $pattern A shell wildcard matched against the name — `*.php`, `*`.
     * @return Collection<File>
     */
    public function files(string $pattern = '*'): Collection
    {
        $files = new Collection(File::class);

        foreach ($this->entries() as $name) {
            if (fnmatch($pattern, $name, FNM_PERIOD)) {
                $files = $files->with($this->file($name));
            }
        }

        return $files->where(static fn(File $file): bool => $file->exists())->settled();
    }

    /**
     * Creates the directory, and any directory above it that is missing.
     *
     * @param int $mode
     * @return bool True if the directory exists afterwards, however it got there — two processes
     *              racing to create the same one both succeed, which is the postcondition anyone
     *              calling this is actually asking about.
     */
    public function create(int $mode = 0o755): bool
    {
        return $this->exists()
            || Diagnostics::muted(fn(): bool => mkdir($this->path, $mode, true))
            || $this->exists();
    }

    /**
     * Removes the files in this directory, and then the directory.
     *
     * Every file, dotfiles included — a fixture's `.gitkeep`, left behind because a listing skipped
     * it, is a directory `rmdir()` refuses. Refuses to descend — a subdirectory left inside means
     * `rmdir()` fails and this answers false, rather than this quietly deleting a tree somebody did
     * not mean to name.
     *
     * **And refuses a link rather than following it.** A link's target is somewhere nobody named,
     * and emptying it through the link is the one mistake a delete that never recurses could still
     * make.
     *
     * @return bool True if there is nothing here afterwards.
     */
    public function remove(): bool
    {
        if (is_link($this->path)) {
            return false;
        }

        if (!$this->exists()) {
            return true;
        }

        foreach ($this->entries() as $name) {
            if (!$this->file($name)->delete()) {
                return false;
            }
        }

        return Diagnostics::muted(fn(): bool => rmdir($this->path));
    }

    /**
     * Every name in this directory, or none where it cannot be read.
     *
     * `.` and `..` among them, harmlessly: both are directories, so neither is ever a file
     * {@link self::files()} answers or {@link self::remove()} deletes.
     *
     * @return list<string>
     */
    #[BareArray(
        'scandir() is the door, the way glob() was before it: what it hands over is names, and what '
        . 'crosses into the rest of this class is Files built from them.',
    )]
    private function entries(): array
    {
        $names = Diagnostics::muted(fn(): array|false => scandir($this->path));

        return $names === false ? [] : $names;
    }
}
