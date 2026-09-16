<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

use JsonException;
use Phpanta\Support\Charset;
use stdClass;

/**
 * The DropMeta class. What a drop is — text or a file, its name, its size — sealed beside its bytes.
 *
 * **Sealed rather than written beside the header**, because a name says something about what a file
 * holds: `passwords.kdbx` is worth knowing before a single byte of it opens. The header keeps only
 * what the store needs without the link — when it goes, and whether it opens once or needs a
 * password; see {@link DropHeader}.
 */
final readonly class DropMeta
{
    /** The longest name, in bytes: what a filesystem takes. */
    private const int MAX_NAME = 255;

    /** How deep the description may nest: an object of scalars. */
    private const int MAX_DEPTH = 2;

    /** A slash, a backslash or a control character: what a name a file may be saved under never holds. */
    private const string NOT_IN_A_NAME = '/[\/\\\\\x00-\x1f\x7f]/';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param DropKind    $kind
     * @param string|null $name The name its bytes are saved under — a file's, and never text's.
     * @param int         $size How many bytes it holds.
     */
    public function __construct(public DropKind $kind, public ?string $name, public int $size) {}

    /**
     * Whether $name may be what a drop's bytes are saved under: one segment of UTF-8, not `.` or `..`,
     * with no slash, backslash or control character, and at most {@link self::MAX_NAME} bytes.
     *
     * A browser saving the drop takes the name from `Content-Disposition` and makes it safe again
     * itself; this is what the name may be before it gets there, so a drop never offers a path.
     *
     * @param string $name
     * @return bool
     */
    public static function isName(string $name): bool
    {
        return $name !== '.'
            && $name !== '..'
            && $name !== ''
            && strlen($name) <= self::MAX_NAME
            && mb_check_encoding($name, Charset::Utf8->value)
            && preg_match(self::NOT_IN_A_NAME, $name) !== 1;
    }

    /**
     * The description $json is, or null where it is not one this wrote.
     *
     * @param string $json
     * @return self|null
     */
    public static function read(string $json): ?self
    {
        try {
            $values = json_decode($json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!$values instanceof stdClass) {
            return null;
        }

        $kind = $values->{DropMetaKey::Kind->value} ?? null;
        $name = $values->{DropMetaKey::Name->value} ?? null;
        $size = $values->{DropMetaKey::Size->value} ?? null;
        $kind = is_string($kind) ? DropKind::tryFrom($kind) : null;

        $named = match ($kind) {
            DropKind::File => is_string($name) && self::isName($name),
            DropKind::Text => $name === null,
            null           => false,
        };

        return $named && is_int($size) && $size >= 0 ? new self($kind, $name, $size) : null;
    }

    /**
     * The description as it is sealed.
     *
     * @return string
     */
    public function json(): string
    {
        // Nothing here fails to encode: the name was held to UTF-8 on its way in.
        return (string) json_encode([
            DropMetaKey::Kind->value => $this->kind->value,
            DropMetaKey::Name->value => $this->name,
            DropMetaKey::Size->value => $this->size,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * How many chunks its bytes are sealed in: one per {@link DropHeader::CHUNK}, and one even for
     * nothing, so every drop ends in a chunk marked as its last.
     *
     * @return int
     */
    public function chunks(): int
    {
        return max(1, intdiv($this->size + DropHeader::CHUNK - 1, DropHeader::CHUNK));
    }

    /**
     * How many bytes chunk $index holds, opened.
     *
     * @param int $index
     * @return int
     */
    public function chunkLength(int $index): int
    {
        return $index === $this->chunks() - 1 ? $this->size - $index * DropHeader::CHUNK : DropHeader::CHUNK;
    }
}
