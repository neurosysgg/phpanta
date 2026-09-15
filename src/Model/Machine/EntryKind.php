<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

/**
 * The EntryKind enum. What a directory entry is, as `lstat()` answers — so a link is a link, whatever
 * it points at.
 */
enum EntryKind: string
{
    case File      = 'file';
    case Directory = 'directory';
    case Link      = 'link';

    /** A device, a socket, a pipe — listed, and never opened. */
    case Other     = 'other';

    /**
     * The kind a mode's file-type bits say.
     *
     * @param int $mode As `lstat()` answers it.
     * @return self
     */
    public static function ofMode(int $mode): self
    {
        return match ($mode & 0o170000) {
            0o100000 => self::File,
            0o040000 => self::Directory,
            0o120000 => self::Link,
            default  => self::Other,
        };
    }

    /**
     * The letter `ls -l` opens a line with for this kind.
     *
     * @return string
     */
    public function mark(): string
    {
        return match ($this) {
            self::File      => '-',
            self::Directory => 'd',
            self::Link      => 'l',
            self::Other     => '?',
        };
    }
}
