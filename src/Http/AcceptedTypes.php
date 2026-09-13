<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Support\Collection;

/**
 * The AcceptedTypes class. What an `Accept` header asked for.
 *
 * {@link AcceptedLanguages} for the other axis, and the same two halves of one decision: this reads
 * the header, and whatever answers by it owes a `Vary: Accept` — see {@link RequestHeader::Accept}.
 *
 * **It never guesses and it never defaults in here.** {@link self::preferred()} takes the
 * representations an answer actually has, the one it gives a request that did not say first, and
 * returns the best of them — or null, when the request named types and none of them is on offer.
 * That null is the difference from languages, where a page always has one to fall back on: a caller
 * that asked for `text/plain` and only `text/plain` has said what it can read, and the honest answer
 * is a `406` rather than a body it cannot.
 */
final readonly class AcceptedTypes
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<MediaRange> $ranges Every entry that parsed, in the order sent.
     */
    private function __construct(private Collection $ranges) {}

    /**
     * Parses one `Accept` header value — `''` for a request that sent none.
     *
     * An entry that is not a range is skipped rather than refused, for {@link AcceptedLanguages}'
     * reason: the header is whatever a client felt like writing, and one unreadable entry means one
     * preference that cannot be honoured, not a request that cannot be answered.
     *
     * @param string $header
     * @return self
     */
    public static function from(string $header): self
    {
        $ranges = [];

        foreach (explode(',', $header) as $entry) {
            $range = MediaRange::parse($entry);

            if ($range !== null) {
                $ranges[] = $range;
            }
        }

        return new self(new Collection(MediaRange::class)->with(...$ranges));
    }

    /**
     * The best of the representations on offer, for this request — or null for none of them.
     *
     * A request that named no type it could be read as naming — no header, or only entries that are
     * not ranges — gets $default, which is `*∕*`'s answer too: curl sends that, and it means "I will
     * read whatever you would have sent a browser". Otherwise each representation weighs what the
     * most specific range naming it says, the heaviest wins, and a tie goes to the earlier argument,
     * so `*∕*` alone still answers $default. A weight of 0 is a refusal, and a representation no
     * range names weighs 0.
     *
     * @param Representation $default         What a request that did not say gets.
     * @param Representation ...$alternatives What it may ask for instead.
     * @return Representation|null
     */
    public function preferred(Representation $default, Representation ...$alternatives): ?Representation
    {
        if ($this->ranges->isEmpty()) {
            return $default;
        }

        $best        = null;
        $bestQuality = 0.0;

        foreach ([$default, ...$alternatives] as $representation) {
            $quality = $this->qualityOf($representation);

            if ($quality > $bestQuality) {
                $best        = $representation;
                $bestQuality = $quality;
            }
        }

        return $best;
    }

    /**
     * How much this request wants $representation, by the most specific range naming it.
     *
     * @param Representation $representation
     * @return float
     */
    private function qualityOf(Representation $representation): float
    {
        $specificity = -1;
        $quality     = 0.0;

        foreach ($this->ranges as $range) {
            $exactness = $range->specificity($representation);

            // Strictly greater, so of two ranges equally specific the first one sent decides.
            if ($exactness !== null && $exactness > $specificity) {
                $specificity = $exactness;
                $quality     = $range->quality;
            }
        }

        return $quality;
    }
}
