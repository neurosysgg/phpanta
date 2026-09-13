<?php

declare(strict_types=1);

namespace Phpanta\Tool\Update;

use Phpanta\Support\Collection;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\Directory;
use Phpanta\Tool\Cli\UsageException;

/**
 * The TarWriter class. Packs a set of directories and files into a ustar archive.
 *
 * **The other half of {@link \Phpanta\Support\TarArchive}, and deliberately not the same class.**
 * That one lives under `src/` because the server needs it; this one lives here because the server
 * must not have it. `src/` ships, so a writer over there would sit on the server as code that builds
 * archives — next to the endpoint that unpacks them. Splitting them costs one duplicated block size
 * and buys a server that can only read.
 *
 * **Written in PHP rather than shelled out to `tar`.** GNU tar's output depends on its version and
 * its flags — it will happily emit pax headers or a long-name record, both of which the reader
 * refuses on purpose. Writing the blocks here means the archive is exactly what the reader accepts,
 * by construction rather than by hoping.
 *
 * **Reproducible**: the same files make the same bytes. Entries are sorted and every header carries
 * the same mtime, so two packs of an unchanged tree differ only where a file did.
 *
 * Only regular files and directories are ever written, so a symlink in the tree is followed to its
 * contents — and one that points nowhere is refused rather than packed as an empty file, which the
 * server would then write over the real one.
 */
final readonly class TarWriter
{
    /** Every header and every data run is padded to this. */
    private const int BLOCK = 512;

    /** ustar's name field, and the reason nothing here may exceed it. */
    private const int MAX_NAME = 100;

    /** What the reader accepts, and therefore all this writes. */
    private const string TYPE_FILE = '0';
    private const string TYPE_DIRECTORY = '5';

    /**
     * The modes written into the header.
     *
     * The server ignores both — {@link \Phpanta\Support\File::write()} and
     * {@link \Phpanta\Support\Directory::create()} set their own — so these exist for `tar x`,
     * which does not. A directory written 0644 lists fine and cannot be entered, so an archive that
     * looks perfectly good to `tar t` fails to extract; being readable by the ordinary tools is
     * half of why this format was chosen, so it is worth getting right.
     */
    private const int MODE_FILE = 0o644;
    private const int MODE_DIRECTORY = 0o755;

    /**
     * The mtime every header carries: the epoch. Nothing reads it — the server writes files at the
     * time it writes them — and a fixed value is what keeps an archive a function of its files.
     */
    private const int MTIME = 0;

    /**
     * Packs $files, keyed by the name each should carry in the archive.
     *
     * The directory entries are derived from the file names rather than passed in, so the archive
     * cannot name a directory no file lives under.
     *
     * @param Collection<PackedFile> $files
     * @return string The uncompressed archive.
     *
     * @throws UsageException if a name is too long for ustar's 100-byte field.
     */
    public static function pack(Collection $files): string
    {
        $archive     = '';
        $directories = [];

        foreach ($files as $file) {
            foreach (self::ancestors($file->name) as $directory) {
                $directories[$directory] = true;
            }
        }

        ksort($directories);

        foreach (array_keys($directories) as $directory) {
            $archive .= self::header($directory . '/', 0, self::TYPE_DIRECTORY, self::MODE_DIRECTORY);
        }

        foreach ($files as $file) {
            $archive .= self::header($file->name, strlen($file->contents), self::TYPE_FILE, self::MODE_FILE)
                . self::padded($file->contents);
        }

        // Two zero blocks end an archive. The reader stops at the first, but writing one would make
        // this produce something `tar tf` complains about, and being readable by the ordinary tools
        // is half of why the format was chosen.
        return $archive . str_repeat("\0", self::BLOCK * 2);
    }

    /**
     * Every directory a name lives under: `a/b/c.php` gives `a` and `a/b`.
     *
     * @param string $name
     * @return list<string>
     */
    private static function ancestors(string $name): array
    {
        $segments = explode('/', $name);
        array_pop($segments);

        $ancestors = [];
        $path      = '';

        foreach ($segments as $segment) {
            $path        = $path === '' ? $segment : $path . '/' . $segment;
            $ancestors[] = $path;
        }

        return $ancestors;
    }

    /**
     * One 512-byte ustar header, with its checksum computed over itself.
     *
     * @param string $name
     * @param int $size
     * @param string $type
     * @param int $mode
     * @return string
     *
     * @throws UsageException
     */
    private static function header(string $name, int $size, string $type, int $mode): string
    {
        if (strlen($name) > self::MAX_NAME) {
            throw new UsageException(sprintf(
                "'%s' is %d bytes, over ustar's %d-byte name field. The reader joins `prefix` and "
                . '`name` but this writer does not split them, because no tree it packs is close.',
                $name,
                strlen($name),
                self::MAX_NAME,
            ));
        }

        // The checksum is computed with its own field read as eight spaces, then written back into
        // it as six octal digits, a NUL and a space. That last detail is the format's, not a
        // choice: a writer that fills all eight with digits produces an archive GNU tar rejects.
        $header = pack(
            'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            $name,
            self::octal($mode, 7),
            self::octal(0, 7),
            self::octal(0, 7),
            self::octal($size, 11),
            self::octal(self::MTIME, 11),
            '        ',
            $type,
            '',
            'ustar',
            '00',
            '',
            '',
            self::octal(0, 7),
            self::octal(0, 7),
            '',
            '',
        );

        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ord($header[$i]);
        }

        // Not self::octal(): that appends a NUL of its own, which would make this nine bytes
        // replacing eight and push every following header off by one. The checksum's own padding is
        // six digits, a NUL and a space — the one numeric field in the format that does not end the
        // way the others do.
        return substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);
    }

    /**
     * A tar numeric field: octal, zero-padded to $width, then NUL-terminated.
     *
     * @param int $value
     * @param int $width
     * @return string
     */
    private static function octal(int $value, int $width): string
    {
        return sprintf('%0' . $width . 'o', $value) . "\0";
    }

    /**
     * A file's bytes, padded up to the next block boundary.
     *
     * @param string $contents
     * @return string
     */
    private static function padded(string $contents): string
    {
        $remainder = strlen($contents) % self::BLOCK;

        return $remainder === 0 ? $contents : $contents . str_repeat("\0", self::BLOCK - $remainder);
    }

    /**
     * Every regular file under $directory, as {@link PackedFile}s named `$prefix/…`. None where the
     * directory is not there.
     *
     * @param Directory $directory
     * @param string $prefix The name the root takes in the archive — `public`, `src`.
     * @return Collection<PackedFile>
     *
     * @throws UsageException if a file under it cannot be read — a dangling symlink, a file this
     *                        user may not open. Packed as the empty string it would be written over
     *                        the server's copy as nothing at all.
     */
    public static function tree(Directory $directory, string $prefix): Collection
    {
        $files = new Collection(PackedFile::class);

        foreach (self::walk($directory->path) as $path) {
            $contents = Diagnostics::muted(static fn(): string|false => file_get_contents($path));

            if ($contents === false) {
                throw new UsageException(sprintf('cannot read %s, so it cannot be packed', $path));
            }

            $files = $files->with(new PackedFile(
                $prefix . '/' . ltrim(substr($path, strlen($directory->path)), '/'),
                $contents,
            ));
        }

        return $files;
    }

    /**
     * Every regular file under $path, recursively, sorted so an archive is reproducible.
     *
     * `scandir()` rather than `glob()`, because a glob reads its argument as a pattern: a project
     * whose path holds a `[` or a `*` listed nothing, and a push of nothing is a push the server
     * mirrors as "delete all of it".
     *
     * @param string $path
     * @return list<string>
     */
    private static function walk(string $path): array
    {
        $paths = [];

        foreach (Diagnostics::muted(static fn(): array|false => scandir($path)) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $entry = $path . '/' . $name;

            if (is_dir($entry)) {
                $paths = array_merge($paths, self::walk($entry));
                continue;
            }

            $paths[] = $entry;
        }

        sort($paths);

        return $paths;
    }
}
