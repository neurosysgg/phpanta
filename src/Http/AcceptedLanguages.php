<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Support\BareArray;
use Phpanta\Text\Language;

/**
 * The AcceptedLanguages class. What an `Accept-Language` header asked for, in order.
 *
 * A class rather than an enum for the reason {@link Security\StrictTransportSecurity} and
 * {@link MimeType} are: the value carries parameters. `de-DE,de;q=0.9,en-GB;q=0.8,*;q=0.1` is a
 * weighted list, and a weighted list is a grammar — so it is parsed once, here, rather than matched
 * with a `str_contains` at whichever page happens to care.
 *
 * It is the **request** counterpart to {@link Vary}, and the two are two halves of one decision:
 * this reads the header, that one declares the reading. A page that used this and did not say so in
 * `Vary` would be a page a cache is free to hand to the wrong visitor — see
 * {@link RequestHeader::AcceptLanguage}.
 *
 * **What it deliberately does not do is guess.** {@link self::preferred()} takes the languages that
 * are actually on offer and returns one of them; there is no "best language" in the abstract, only
 * the best of what a page has. A header naming nothing we have, or no header at all, comes back as
 * the first language offered — so the default is written at the call site, in the argument order,
 * rather than hidden in here.
 */
final readonly class AcceptedLanguages
{
    /**
     * One entry per language range, as `primary subtag => quality`, best first.
     *
     * Keyed by the **primary subtag** rather than the full range, because that is the only part
     * a page can act on: an app offers languages rather than regions, and `en-GB`, `en-US` and `en`
     * all want the English one. A range that is only a region apart from another keeps the higher
     * quality of the two, which is what a client means by sending both.
     *
     * @var array<string, float>
     */
    #[BareArray(
        'accumulated in a loop and then sorted. from() updates a key it has already seen with '
        . 'max(), and arsort() puts the best first; with() copies and no collection sorts.',
    )]
    private array $qualities;

    /**
     * @param array<string, float> $qualities
     */
    #[BareArray('takes what from() accumulated, for the reason stated on the property')]
    private function __construct(array $qualities)
    {
        $this->qualities = $qualities;
    }

    /**
     * Parses one `Accept-Language` header value.
     *
     * RFC 9110 §12.5.4: a comma-separated list of language ranges, each optionally followed by
     * `;q=` and a weight between 0 and 1. A missing weight is 1.0 — the client's strongest
     * preference — and a weight of 0 means "not this one", which is kept rather than dropped so
     * {@link self::preferred()} can refuse it explicitly.
     *
     * **Anything unreadable is skipped rather than rejected.** This is the one header on the site
     * whose value is a visitor's browser settings, so it arrives however some client felt like
     * writing it; a malformed entry means one preference cannot be honoured, not that the page
     * cannot be served. `''` parses to no preferences at all, which is what a request carrying no
     * such header should look like.
     *
     * @param string $header The raw header value, or `''` if it did not arrive.
     * @return self
     */
    public static function from(string $header): self
    {
        $qualities = [];

        foreach (explode(',', $header) as $entry) {
            [$range, $quality] = self::entry($entry);

            if ($range === null) {
                continue;
            }

            // max(), not assignment: `en-GB;q=0.9, en;q=0.5` collapses to one primary subtag, and
            // what the client meant by the pair is the better of the two, not the last one written.
            $qualities[$range] = max($qualities[$range] ?? 0.0, $quality);
        }

        arsort($qualities);

        return new self($qualities);
    }

    /**
     * The best of what this page has, for this visitor.
     *
     * Ties and misses both go to $default, which is why it is a parameter of its own rather than
     * the first of a variadic: a page always has a language, so "no languages offered" is a state
     * worth making unrepresentable instead of guarding against.
     * `preferred(Language::English, Language::German)` is an English page that will speak German if
     * asked to, and swapping the arguments is a German page that will speak English.
     *
     * A wildcard `*` matches any offered language, so `*;q=0.5` gives everything on offer that
     * weight — and an explicit `q=0` on a language means the client asked *not* to be given it,
     * which is honoured even where the wildcard would otherwise have covered it.
     *
     * @param Language    $default      What to answer when the header asks for none of them.
     * @param Language ...$alternatives The other languages this page has.
     * @return Language
     */
    public function preferred(Language $default, Language ...$alternatives): Language
    {
        $best        = $default;
        $bestQuality = 0.0;

        foreach ([$default, ...$alternatives] as $language) {
            $quality = $this->qualityOf($language);

            // Strictly greater, so a tie leaves the earlier argument in place — which is what makes
            // the first one the default rather than merely the first tried.
            if ($quality > $bestQuality) {
                $best        = $language;
                $bestQuality = $quality;
            }
        }

        return $best;
    }

    /**
     * How much this visitor wants $language, between 0 and 1.
     *
     * @param Language $language
     * @return float
     */
    private function qualityOf(Language $language): float
    {
        return $this->qualities[$language->value] ?? $this->qualities['*'] ?? 0.0;
    }

    /** A weight: `0` to `1`, with at most three decimals — RFC 9110 §12.4.2's `qvalue`. */
    private const string WEIGHT = '/\A(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)\z/';

    /**
     * One list entry, as its primary subtag and weight — or a null range if it is not one.
     *
     * @param string $entry
     * @return array{?string, float}
     */
    #[BareArray(
        'a tuple, not a group: a subtag and a weight, two types in a fixed order, which is the one '
        . 'shape a homogeneous collection cannot hold.',
    )]
    private static function entry(string $entry): array
    {
        $parts   = explode(';', $entry);
        $range   = strtolower(trim($parts[0]));
        $quality = 1.0;

        foreach (array_slice($parts, 1) as $parameter) {
            $parameter = strtolower(str_replace(' ', '', $parameter));

            if (!str_starts_with($parameter, 'q=')) {
                continue;
            }

            // A weight this cannot read drops its entry. `q=high` is not a preference anyone can
            // honour: read as the 1.0 an absent weight means, an unreadable entry became the
            // strongest in the list, and read by (float) it would become a refusal. Neither is what
            // the client said, so the entry says nothing — which is also what `q=2` says.
            $written = substr($parameter, 2);

            if (preg_match(self::WEIGHT, $written) !== 1) {
                return [null, 0.0];
            }

            $quality = (float) $written;
        }

        if ($range === '') {
            return [null, 0.0];
        }

        // The primary subtag and nothing else — `de-AT` and `de-DE` are both the German half here.
        // A range is `*` or one-to-eight alphanumerics per subtag; anything else is not a range.
        [$primary] = explode('-', $range, 2);

        return preg_match('/\A(\*|[a-z]{1,8})\z/', $primary) === 1 ? [$primary, $quality] : [null, 0.0];
    }
}
