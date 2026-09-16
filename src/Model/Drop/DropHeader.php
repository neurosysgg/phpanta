<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

/**
 * The DropHeader class. The first bytes of a drop's file: what the store may know of it without its
 * link, and nothing else.
 *
 * ```
 * 0   magic `PDRP`          4
 * 4   version, 1            1
 * 5   flags: once, locked   1
 * 6   spare, zero           2
 * 8   created               8   seconds, big-endian
 * 16  expires               8
 * 24  PBKDF2 iterations     4   zero where it needs no password
 * 28  salt                  16
 * 44  sealed meta's length  4
 * 48  the sealed meta, then each chunk sealed: ciphertext, then its 16-byte tag
 * ```
 *
 * **Plain, and bound in.** The store reads it to sweep what has expired and to say whether a password
 * is needed, which it must do without a link; and every seal in the file takes the whole header as
 * associated data, so a byte of it changed — a later expiry, a drop made to open more than once — is
 * a drop that no longer opens. Each chunk also binds its index and whether it is the last, so chunks
 * cannot be reordered, dropped or cut off without the seal saying so.
 */
final readonly class DropHeader
{
    /** How many bytes the header is. */
    public const int LENGTH = 48;

    /** How many bytes a chunk holds, opened — every chunk but the last, which holds what is left. */
    public const int CHUNK = 65_536;

    /** How many bytes each seal's tag is: GCM's, whole. */
    public const int TAG = 16;

    /** How many bytes of salt each drop has — the key's and the password's. */
    public const int SALT_BYTES = 16;

    /** The most PBKDF2 iterations a header may name, so no file can hold a request for a day. */
    public const int MAX_ITERATIONS = 10_000_000;

    /** The longest the sealed description may be, tag included: a name and two short words. */
    private const int MAX_META = 1_024;

    /** What every drop's file starts with. */
    private const string MAGIC = 'PDRP';

    /** The format this reads and writes. */
    private const int VERSION = 1;

    /** The flag of a drop that is gone once it has been read. */
    private const int ONCE = 0b01;

    /** The flag of a drop that needs a password besides its link. */
    private const int LOCKED = 0b10;

    /**
     * A 64-bit number, big-endian, as `pack()` writes it. A 32-bit field is its last four bytes — see
     * {@link self::four()} — so the header is written in one `pack()` format.
     */
    private const string U64 = 'J';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param bool   $once       Whether it is gone once it has been read.
     * @param bool   $locked     Whether it needs a password besides its link.
     * @param int    $created    When it was made.
     * @param int    $expires    When it is gone.
     * @param int    $iterations The PBKDF2 rounds its password is stretched with; zero with none.
     * @param string $salt       Random bytes, {@link self::SALT_BYTES} of them.
     * @param int    $metaLength How many bytes the sealed description after this is.
     */
    public function __construct(
        public bool   $once,
        public bool   $locked,
        public int    $created,
        public int    $expires,
        public int    $iterations,
        public string $salt,
        public int    $metaLength,
    ) {}

    /**
     * The header $bytes are, or null where they are not one this wrote.
     *
     * @param string $bytes
     * @return self|null
     */
    public static function read(string $bytes): ?self
    {
        if (strlen($bytes) !== self::LENGTH || !str_starts_with($bytes, self::MAGIC)) {
            return null;
        }

        $flags      = ord($bytes[5]);
        $created    = self::number(substr($bytes, 8, 8));
        $expires    = self::number(substr($bytes, 16, 8));
        $iterations = self::number(str_repeat(chr(0), 4) . substr($bytes, 24, 4));
        $metaLength = self::number(str_repeat(chr(0), 4) . substr($bytes, 44, 4));
        $locked     = ($flags & self::LOCKED) !== 0;

        $whole = ord($bytes[4]) === self::VERSION
            && ($flags & ~(self::ONCE | self::LOCKED)) === 0
            && substr($bytes, 6, 2) === str_repeat(chr(0), 2)
            && $created <= $expires
            && ($locked ? $iterations >= 1 && $iterations <= self::MAX_ITERATIONS : $iterations === 0)
            && $metaLength > self::TAG
            && $metaLength <= self::MAX_META;

        return $whole ? new self(
            ($flags & self::ONCE) !== 0,
            $locked,
            $created,
            $expires,
            $iterations,
            substr($bytes, 28, self::SALT_BYTES),
            $metaLength,
        ) : null;
    }

    /**
     * The header as it is written, and as every seal in the file binds it.
     *
     * @return string
     */
    public function bytes(): string
    {
        $flags = ($this->once ? self::ONCE : 0) | ($this->locked ? self::LOCKED : 0);

        return self::MAGIC
            . chr(self::VERSION)
            . chr($flags)
            . str_repeat(chr(0), 2)
            . pack(self::U64, $this->created)
            . pack(self::U64, $this->expires)
            . self::four($this->iterations)
            . $this->salt
            . self::four($this->metaLength);
    }

    /**
     * Whether it is gone at $now.
     *
     * @param int $now
     * @return bool
     */
    public function isExpired(int $now): bool
    {
        return $this->expires <= $now;
    }

    /**
     * What chunk $index is sealed with beside its bytes: the header, the index, and whether it is the
     * last.
     *
     * @param int  $index
     * @param bool $last
     * @return string
     */
    public function chunkData(int $index, bool $last): string
    {
        return $this->bytes() . self::counter($index) . chr($last ? 1 : 0);
    }

    /**
     * How long the whole file of a drop $meta describes is — what a file cut short, or grown, is not.
     *
     * @param DropMeta $meta
     * @return int
     */
    public function fileSize(DropMeta $meta): int
    {
        return self::LENGTH + $this->metaLength + $meta->size + $meta->chunks() * self::TAG;
    }

    /**
     * $index as eight bytes, big-endian — what a chunk's nonce and associated data count with.
     *
     * @param int $index
     * @return string
     */
    public static function counter(int $index): string
    {
        return pack(self::U64, $index);
    }

    /**
     * $value as four bytes, big-endian: the last four of its eight. Every value written this way is
     * below 2³², which {@link self::read()} holds it to on the way back.
     *
     * @param int $value
     * @return string
     */
    private static function four(int $value): string
    {
        return substr(pack(self::U64, $value), 4);
    }

    /**
     * The number eight bytes hold, big-endian.
     *
     * @param string $bytes
     * @return int
     */
    private static function number(string $bytes): int
    {
        $unpacked = unpack(self::U64, $bytes);

        return $unpacked === false ? -1 : (int) $unpacked[1];
    }
}
