<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Exception\SecurityPolicyException;
use Phpanta\Support\Collection;

/**
 * The RobotsPolicy class. What a crawler may do with a response, as typed directives.
 *
 * Composed from a variadic of {@link RobotsDirective} and refusing an empty list, the same shape as
 * {@link CacheControl::of()} and {@link Vary::on()} — an empty `X-Robots-Tag` is a malformed
 * header, not a permissive one, and a page with nothing to ask simply omits it.
 *
 * **Note what this is and is not.** It is *not* the thing keeping gated pages out of search results —
 * {@link \Phpanta\Service\Auth} is, because a crawler gets a 401 and there is nothing behind it to
 * index. What this covers is the case the gate cannot: a page that has already been opened with the
 * password, by a browser or an extension that then reports what it saw. That is a narrow gap and
 * this is a cheap thing to put in it.
 *
 * **`robots.txt` is deliberately not the tool for this.** A `Disallow: /private/` would be a public
 * file naming the private half of the site, which is the opposite of the point. A header says it
 * only to whoever was already let in.
 */
final readonly class RobotsPolicy implements HeaderValue
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<RobotsDirective> $directives
     */
    private function __construct(private Collection $directives) {}

    /**
     * Everything the framework knows how to ask for, which is what a gated page asks.
     *
     * One factory rather than a set of them, because there is one answer here: a page is either
     * public and says nothing, or it is behind a gate and says all three.
     *
     * @return self
     */
    public static function hide(): self
    {
        return self::of(
            RobotsDirective::NoIndex,
            RobotsDirective::NoFollow,
            RobotsDirective::NoArchive,
        );
    }

    /**
     *
     * @param RobotsDirective ...$directives
     * @return self
     * @throws SecurityPolicyException if no directive is given.
     */
    public static function of(RobotsDirective ...$directives): self
    {
        if ($directives === []) {
            throw new SecurityPolicyException(
                'RobotsPolicy::of() needs at least one directive; omit the header instead.',
            );
        }

        return new self(new Collection(RobotsDirective::class)->with(...$directives));
    }

    /**
     * Returns the header value: `noindex, nofollow, noarchive`.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->directives->map(static fn(RobotsDirective $d): string => $d->value)->join(', ');
    }
}
