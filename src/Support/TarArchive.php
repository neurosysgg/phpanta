<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Phpanta\Exception\UpdateException;

/**
 * The TarArchive class. Reads a ustar archive into {@link TarEntry}s, refusing everything it cannot
 * vouch for.
 *
 * **Hand-rolled rather than `PharData`, and that is a security decision before a dependency one.**
 * `PharData::extractTo()` decides for itself what a member name means and what a link points at,
 * which is exactly the decision this class must not delegate: the names arriving here came off the
 * network. Reading the format directly is about a hundred lines of `unpack()` — the ordinary
 * idiom for a binary format in PHP — and it buys total control over which
 * member types exist and which names are allowed. It also drops a dependency on an extension that
 * shared hosts disable, though that is the smaller half.
 *
 * **Nothing here touches the filesystem.** It parses bytes into values; where those values are
 * allowed to land is {@link \Phpanta\Model\Update\UpdateRoot}'s question, and writing them is
 * {@link \Phpanta\Service\UpdateApplier}'s. The split is deliberate: this class knows nothing
 * about the app, so what it refuses it refuses for reasons that are true of any archive.
 *
 * **The refusals are the point, so they are exhaustive rather than illustrative.** Only regular
 * files and directories survive. A symlink, a hardlink, a device node, a fifo, a GNU long-name
 * record and a pax header are each rejected by name — not skipped, rejected, because an archive
 * containing one is not an archive the push produced and the right answer is to stop rather than
 * to quietly unpack the rest. Every one of those is a documented way to write outside an extraction
 * root, and none of them is a shape `tar -czf` produces for an ordinary source tree, which packs as
 * regular files and directories and nothing else.
 */
final readonly class TarArchive
{
    /** Every tar header and every data run is padded to this. */
    private const int BLOCK = 512;

    /** Where the checksum field sits, and how wide — it is computed with these bytes read as spaces. */
    private const int CHECKSUM_OFFSET = 148;
    private const int CHECKSUM_LENGTH = 8;

    /**
     * Parses $bytes — an uncompressed ustar archive — into its members, in archive order.
     *
     * Order is kept because the applier writes in it, and a directory entry precedes the files
     * under it in anything `tar` produces.
     *
     * @param string $bytes
     * @return Collection<TarEntry>
     *
     * @throws UpdateException if the archive is truncated, mis-sized, checksum-mismatched, or holds
     *                         a member of any type but a regular file or a directory.
     */
    public static function parse(string $bytes): Collection
    {
        $entries = new Collection(TarEntry::class);
        $length  = strlen($bytes);
        $offset  = 0;
        $ended   = false;

        while ($offset + self::BLOCK <= $length) {
            $header = substr($bytes, $offset, self::BLOCK);

            // Two consecutive zero blocks end an archive, but one is enough to stop on: nothing
            // follows it that this reader would trust anyway, and requiring the pair would make a
            // truncated-but-terminated archive parse further than a truncated one.
            if (trim($header, "\0") === '') {
                $ended = true;
                break;
            }

            $entry = self::header($header, $offset);
            $size  = $entry['length'];
            $start = $offset + self::BLOCK;

            if ($start + $size > $length) {
                throw new UpdateException(sprintf(
                    "the archive declares %d bytes for '%s' but only %d follow — it is truncated",
                    $size,
                    $entry['name'],
                    $length - $start,
                ));
            }

            $entries = $entries->with(new TarEntry(
                $entry['name'],
                $entry['directory'] ? '' : substr($bytes, $start, $size),
                $entry['directory'],
            ));

            // Data is padded up to the next block boundary; a directory declares no data at all.
            $offset = $start + (int) ceil($size / self::BLOCK) * self::BLOCK;
        }

        // **The end-of-archive block is required, not assumed.** Without it, an archive cut off at a
        // block boundary — a packing bug on the signing side, which a signature faithfully vouches
        // for — reads as a complete, smaller tree, and the mirror then deletes everything that was
        // in the part that did not arrive. A trailing partial block is the same fault a few bytes on.
        if (!$ended) {
            throw new UpdateException(
                'the archive ends without an end-of-archive block, so it is truncated — whatever it '
                . 'held after its last member never arrived',
            );
        }

        // After the marker, tar pads with zeros to a record boundary and writes nothing else.
        if (trim(substr($bytes, $offset), "\0") !== '') {
            throw new UpdateException('the archive carries bytes after its end-of-archive block');
        }

        return $entries;
    }

    /**
     * Reads one 512-byte header, checks it, and returns what the caller needs from it.
     *
     * @param string $header
     * @param int $offset Where this header sits, so a failure can say where.
     * @return array{name: string, length: int, directory: bool}
     *
     * @throws UpdateException
     */
    #[BareArray(
        "unpack's own shape on one side and three unrelated values on the other — a name, a size "
        . 'and a kind, which is the tuple TarEntry exists to replace everywhere except here, '
        . 'between two private methods one screen apart.',
    )]
    private static function header(string $header, int $offset): array
    {
        // The field names in this format string are ours, not ustar's — the format numbers its
        // fields and this is the only place they are given words. `length` and `kind` rather than
        // the obvious `size` and `type` because those two are already vocabulary elsewhere here
        // (a manifest key, an openssl key attribute), and a word shared by coincidence between two
        // files is the exact shape GuidelineTest is watching for. Naming them apart costs nothing
        // and means neither file needs an excuse.
        /** @var array<string, string> $fields */
        $fields = unpack(
            'a100name/a8mode/a8uid/a8gid/a12length/a12mtime/a8checksum/a1kind/a100link'
            . '/a6magic/a2version/a32uname/a32gname/a8devmajor/a8devminor/a155prefix',
            $header,
        );

        self::verifyChecksum($header, $fields['checksum'], $offset);

        $kind = TarMemberType::tryFrom(rtrim($fields['kind'], "\0"));

        if ($kind === null) {
            throw new UpdateException(sprintf(
                'the archive holds a member of a type no tar defines, at offset %d',
                $offset,
            ));
        }

        if (!$kind->isAccepted()) {
            throw new UpdateException(sprintf(
                "the archive holds %s ('%s'), which this site never packs and never unpacks",
                $kind->describe(),
                self::name($fields),
            ));
        }

        $directory = $kind->isDirectory();

        return [
            'name'      => self::name($fields),
            'length'    => $directory ? 0 : self::octal($fields['length'], 'length', $offset),
            'directory' => $directory,
        ];
    }

    /**
     * The member's full name: ustar splits a long one across `prefix` and `name`.
     *
     * The split is joined here rather than ignored because ignoring it is how a reader silently
     * gets the *wrong* name for a deep path — the 100-byte `name` field alone would hand back a
     * plausible-looking tail. A typical tree has no path long enough to need it against a 100-byte
     * field, so this is a correctness guard rather than a busy code path, and it costs one
     * concatenation.
     *
     * @param array<string, string> $fields
     * @return string
     */
    #[BareArray("unpack's own shape, passed one private method along; the door is in header().")]
    private static function name(array $fields): string
    {
        $name   = rtrim($fields['name'], "\0");
        $prefix = rtrim($fields['prefix'], "\0");

        return $prefix === '' ? $name : $prefix . '/' . $name;
    }

    /**
     * The ustar header checksum: every byte summed, with the checksum field itself read as spaces.
     *
     * Worth verifying even though a signature already covers the whole payload, because the two
     * answer different questions. The signature says the bytes are ours; this says they are a tar.
     * A mismatch here means the framing is off — which, in a format that is nothing but offsets,
     * means every name and size after it is being read out of the middle of somebody's file.
     *
     * @param string $header
     * @param string $stored
     * @param int $offset
     * @return void
     *
     * @throws UpdateException
     */
    private static function verifyChecksum(string $header, string $stored, int $offset): void
    {
        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $inChecksumField = $i >= self::CHECKSUM_OFFSET
                && $i < self::CHECKSUM_OFFSET + self::CHECKSUM_LENGTH;

            $sum += $inChecksumField ? 32 : ord($header[$i]);
        }

        if ($sum !== self::octal($stored, 'checksum', $offset)) {
            throw new UpdateException(sprintf(
                'the tar header at offset %d does not match its own checksum, so the archive is '
                . 'not framed where it says it is',
                $offset,
            ));
        }
    }

    /**
     * A tar numeric field: octal, space- and NUL-padded.
     *
     * Validated rather than fed straight to `octdec()`, which answers `0` for a field full of
     * rubbish — and a size of zero is a perfectly ordinary value, so the failure would be a member
     * silently read as empty and the walk desynchronised behind it.
     *
     * @param string $field
     * @param string $what Which field, for the message.
     * @param int $offset
     * @return int
     *
     * @throws UpdateException
     */
    private static function octal(string $field, string $what, int $offset): int
    {
        $digits = trim($field, " \0");

        if ($digits === '' || preg_match('/\A[0-7]+\z/', $digits) !== 1) {
            throw new UpdateException(sprintf(
                "the tar %s field at offset %d is not an octal number ('%s')",
                $what,
                $offset,
                $digits,
            ));
        }

        return (int) octdec($digits);
    }
}
