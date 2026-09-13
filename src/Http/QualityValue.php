<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The QualityValue class. The weight a list entry of an `Accept`-family header carries.
 *
 * RFC 9110 §12.4.2: `;q=` and a number from 0 to 1 with at most three decimals, where 1 is the
 * client's strongest preference and 0 means "not this". Both {@link AcceptedLanguages} and
 * {@link MediaRange} read one, and read it the same way, so the grammar is written here once rather
 * than twice.
 *
 * **A weight this cannot read drops its entry.** `q=high` is not a preference anyone can honour:
 * read as the 1.0 an absent weight means, an unreadable entry would become the strongest in the list,
 * and read by `(float)` it would become a refusal. Neither is what the client said, so the entry says
 * nothing — which is also what `q=2` says.
 */
final readonly class QualityValue
{
    /** How a weight parameter opens, spaces and case aside. */
    private const string PREFIX = 'q=';

    /** A weight: `0` to `1`, with at most three decimals — RFC 9110 §12.4.2's `qvalue`. */
    private const string WEIGHT = '/\A(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)\z/';

    /**
     * The weight $parameters give: 1.0 where none of them is a weight, the last weight where one
     * is, and null where a weight cannot be read.
     *
     * @param string ...$parameters An entry's parameters, each as it was sent after its `;`.
     * @return float|null
     */
    public static function of(string ...$parameters): ?float
    {
        $quality = 1.0;

        foreach ($parameters as $parameter) {
            $parameter = strtolower(str_replace(' ', '', $parameter));

            if (!str_starts_with($parameter, self::PREFIX)) {
                continue;
            }

            $written = substr($parameter, strlen(self::PREFIX));

            if (preg_match(self::WEIGHT, $written) !== 1) {
                return null;
            }

            $quality = (float) $written;
        }

        return $quality;
    }
}
