<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The ContentDisposition class. Whether a browser shows a file where it lands, or saves it — and
 * under which name.
 *
 * **Inline is the exception here, and a caller asks for it by kind.** A file the admin serves is
 * whatever was on the disk, and one a browser renders in the admin's own origin is a page that origin
 * vouches for; so the admin's `machine` service shows a picture, a recording or plain text where it
 * lands, and hands everything else over as an attachment. See
 * {@link \Phpanta\Model\Machine\MediaKind}.
 *
 * **The name is written twice, as RFC 6266 has it**: `filename*` in UTF-8, percent-encoded, which
 * every browser in use reads, and a plain `filename` beside it with anything outside printable ASCII
 * — and the quote and backslash its grammar escapes — replaced, for anything older. Neither can carry
 * a line break, so the name cannot end the header early.
 */
final readonly class ContentDisposition implements HeaderValue
{
    /** What stands in, in the plain name, for a character its quoted string cannot hold. */
    private const string STAND_IN = '_';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param bool        $inline Whether to show the file where it lands.
     * @param string|null $name   The name to save it under, or null for none.
     */
    private function __construct(private bool $inline, private ?string $name) {}

    /**
     * Shown where it lands — an image in the page, a recording in its player.
     *
     * @return self
     */
    public static function inline(): self
    {
        return new self(true, null);
    }

    /**
     * Saved, under $name.
     *
     * @param string $name The file's own name; any path in it is the caller's to have taken off.
     * @return self
     */
    public static function attachment(string $name): self
    {
        return new self(false, $name);
    }

    /**
     * @return string
     */
    public function render(): string
    {
        if ($this->inline) {
            return 'inline';
        }

        $name  = (string) $this->name;
        $plain = (string) preg_replace('/[^\x20-\x7e]|["\\\\]/', self::STAND_IN, $name);

        return sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $plain, rawurlencode($name));
    }
}
