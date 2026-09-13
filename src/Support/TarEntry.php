<?php

declare(strict_types=1);

namespace Phpanta\Support;

/**
 * The TarEntry class. One member of a tar archive: a name, what kind of thing it is, and — for a
 * regular file — its contents.
 *
 * A value object rather than a tuple, for the reason {@link \Phpanta\View\Html\Attribute} is one:
 * the alternative is an `array{string, string, bool}` destructured at the one place that reads it,
 * where `[$name, $contents, $isDirectory] = $entry` only reads correctly if you already know the
 * answer.
 *
 * The kind is a bool rather than a `TarEntryType` enum on purpose. {@link TarArchive} refuses every
 * type but regular-file and directory before an entry is ever constructed, so by the time one
 * exists there are exactly two possibilities left — and an enum whose vocabulary is wider than what
 * can reach it would suggest a hardlink or a device node might turn up here, which is the one thing
 * that parser exists to guarantee cannot.
 */
final readonly class TarEntry
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $name The member's path, as written in the archive — always relative, always
     *                     forward-slashed, already validated by {@link TarArchive::parse()}.
     * @param string $contents The member's bytes; always `''` for a directory.
     * @param bool $isDirectory
     */
    public function __construct(
        public string $name,
        public string $contents,
        public bool   $isDirectory,
    ) {}
}
