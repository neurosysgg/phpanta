<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Exception\SecurityPolicyException;
use Phpanta\Support\Collection;

/**
 * The CacheControl class. Whether a response may be reused, as typed directives.
 *
 * Two answers on this site and they are opposites, which is the whole reason this is a type rather
 * than a string literal at each call site. {@link self::revalidate()} is every public document:
 * keep it, ask first, usually be told 304. {@link self::doNotStore()} is the one page behind the
 * admin gate: do not write it down at all.
 *
 * Composed from a variadic of {@link CacheDirective}, the same shape as
 * {@link Security\PermissionsPolicy::deny()} — and refusing an empty list for the same reason it
 * does: an empty `Cache-Control` is not a weaker instruction, it is a malformed header.
 */
final readonly class CacheControl implements HeaderValue
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<CacheDirective> $directives
     */
    private function __construct(private Collection $directives) {}

    /**
     * Keep it, but ask before reusing it.
     *
     * What every public document says. The freshness question is answered by the `ETag` that
     * travels with it rather than by a lifetime here — see {@link ViewResponse::cacheHeaders()}
     * for why a lifetime of any size would be the wrong answer on this particular site.
     *
     * @return self
     */
    public static function revalidate(): self
    {
        return self::of(CacheDirective::NoCache);
    }

    /**
     * Do not write it down, and not in anything in between either.
     *
     * `private` alongside `no-store` is belt and braces: `no-store` already covers shared caches,
     * and saying both is what an intermediary that only understood one of them would need.
     *
     * @return self
     */
    public static function doNotStore(): self
    {
        return self::of(CacheDirective::NoStore, CacheDirective::Private);
    }

    /**
     *
     * @param CacheDirective ...$directives
     * @return self
     * @throws SecurityPolicyException if no directive is given — an empty `Cache-Control` is a
     *                                 malformed header rather than a permissive one.
     */
    public static function of(CacheDirective ...$directives): self
    {
        if ($directives === []) {
            throw new SecurityPolicyException(
                'CacheControl::of() needs at least one directive; omit the header instead.',
            );
        }

        return new self(new Collection(CacheDirective::class)->with(...$directives));
    }

    /**
     * Returns the header value: `no-store, private`.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->directives
            ->map(static fn(CacheDirective $directive): string => $directive->value)
            ->join(', ');
    }
}
