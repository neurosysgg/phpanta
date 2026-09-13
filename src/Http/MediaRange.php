<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The MediaRange class. One entry of an `Accept` header: a type, a subtype, and how much it is
 * wanted.
 *
 * RFC 9110 §12.5.1: `type/subtype`, either half of which may be `*` — though `*` before the slash
 * only as `*∕*` — then parameters, one of which may be the weight `q`. A range is a pattern, not a
 * type, which is why this is not a {@link MimeType}: `text/*` names no type anything is sent as.
 *
 * **Parameters other than `q` are read past, not honoured.** `text/html;level=1` is a more specific
 * range than `text/html` in the RFC's reading, and nothing here answers in levels, so the range is
 * taken to mean the type it names. A weight that cannot be read drops the whole entry — see
 * {@link QualityValue}, which reads the weight for this class and {@link AcceptedLanguages} alike.
 */
final readonly class MediaRange
{
    /** RFC 9110 §5.6.2's `token`: what either half of a range may be spelled with. */
    private const string RANGE = "#\\A([!\\#$%&'*+.^_`|~0-9a-z-]+)/([!\\#$%&'*+.^_`|~0-9a-z-]+)\\z#";

    /** How exactly a range names a representation: the type and subtype, the type alone, anything. */
    private const int EXACT = 2;
    private const int TYPE = 1;
    private const int ANY = 0;

    /**
     * Constructs an instance of {@link self}.
     *
     * Private, so a range is one {@link self::parse()} read rather than two halves nobody checked.
     *
     * @param string $type    Lower-cased, or `*`.
     * @param string $subtype Lower-cased, or `*`.
     * @param float  $quality Between 0 and 1; 0 means "not this".
     */
    private function __construct(
        public string $type,
        public string $subtype,
        public float  $quality,
    ) {}

    /**
     * One list entry, or null if it is not a range.
     *
     * @param string $entry One comma-separated entry, as it was sent.
     * @return self|null
     */
    public static function parse(string $entry): ?self
    {
        $parts = explode(';', $entry);

        if (preg_match(self::RANGE, strtolower(trim($parts[0])), $halves) !== 1) {
            return null;
        }

        [, $type, $subtype] = $halves;

        // `*/html` is not a range: the wildcard type stands only for every type at once.
        if ($type === '*' && $subtype !== '*') {
            return null;
        }

        $quality = QualityValue::of(...array_slice($parts, 1));

        return $quality === null ? null : new self($type, $subtype, $quality);
    }

    /**
     * How exactly this range names $representation — higher is more exact — or null where it does
     * not name it at all.
     *
     * RFC 9110 §12.5.1 lets the most specific range decide a type's weight, so
     * `text/*;q=0.1, text/html` wants HTML at 1 and plain text at 0.1, whatever order they came in.
     *
     * @param Representation $representation
     * @return int|null
     */
    public function specificity(Representation $representation): ?int
    {
        [$type, $subtype] = explode('/', $representation->value, 2);

        return match (true) {
            $this->type === $type && $this->subtype === $subtype => self::EXACT,
            $this->type === $type && $this->subtype === '*'      => self::TYPE,
            $this->type === '*'                                  => self::ANY,
            default                                              => null,
        };
    }
}
