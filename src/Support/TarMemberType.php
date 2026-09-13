<?php

declare(strict_types=1);

namespace Phpanta\Support;

/**
 * The TarMemberType enum. The ustar type byte — every kind of thing a tar member can be, and which
 * three of them this site will read.
 *
 * **The whole vocabulary is here rather than only the accepted part**, which is the opposite of how
 * an allowlist is usually written and is deliberate. {@link TarArchive} has to refuse a symlink by
 * name in order to say *what* it refused, and a reader who wants to know whether hardlinks are
 * handled should be able to find the answer by reading one enum rather than by proving a negative
 * about a `match`. Every case that is not accepted is a documented way to write outside an
 * extraction root or to make a member's real name arrive somewhere the parser is not looking.
 *
 * It also keeps those bytes out of {@link TarArchive} as literals, which matters here for a reason
 * beyond tidiness: `'0'` is a tar type byte in one file and an entirely unrelated default in
 * another, and a vocabulary spelled out at its use site is the exact shape `GuidelineTest` exists
 * to catch. An enum declaration is where a vocabulary is meant to live.
 */
enum TarMemberType: string
{
    /** A regular file. */
    case File = '0';

    /**
     * A regular file, as very old writers spelled it: the type byte left NUL.
     *
     * `unpack`'s `a1` trims the NUL away, so it arrives as the empty string. Kept as a case rather
     * than normalised at the call site because it is a real spelling of a real type, and the place
     * to say so is here.
     */
    case FileLegacy = '';

    /** A directory. */
    case Directory = '5';

    case Hardlink = '1';
    case Symlink = '2';
    case CharacterDevice = '3';
    case BlockDevice = '4';
    case Fifo = '6';
    case Contiguous = '7';
    case LongName = 'L';
    case LongLink = 'K';
    case PaxExtended = 'x';
    case PaxGlobal = 'g';

    /**
     * Whether this site will read a member of this type.
     *
     * @return bool
     */
    public function isAccepted(): bool
    {
        return match ($this) {
            self::File, self::FileLegacy, self::Directory => true,
            default                                       => false,
        };
    }

    /**
     * Whether a member of this type is a directory.
     *
     * @return bool
     */
    public function isDirectory(): bool
    {
        return $this === self::Directory;
    }

    /**
     * What this type is, for the sentence a refusal prints.
     *
     * Worded as a noun phrase so it reads inside "the archive holds …". Named individually rather
     * than lumped into one "unsupported member", because these are not equally ordinary mistakes: a
     * symlink or a hardlink in a payload is an attempt to write outside the roots, while a long-name
     * or pax record means the *name* is somewhere the reader is not looking — which is worse than an
     * unknown type, because the name it does read is a plausible wrong one.
     *
     * @return string
     */
    public function describe(): string
    {
        return match ($this) {
            self::File, self::FileLegacy => 'a regular file',
            self::Directory              => 'a directory',
            self::Hardlink               => 'a hardlink',
            self::Symlink                => 'a symlink',
            self::CharacterDevice        => 'a character device',
            self::BlockDevice            => 'a block device',
            self::Fifo                   => 'a fifo',
            self::Contiguous             => 'a contiguous file',
            self::LongName               => 'a GNU long-name record',
            self::LongLink               => 'a GNU long-link record',
            self::PaxExtended            => 'a pax extended header',
            self::PaxGlobal              => 'a pax global header',
        };
    }
}
