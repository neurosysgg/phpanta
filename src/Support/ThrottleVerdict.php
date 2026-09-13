<?php

declare(strict_types=1);

namespace Phpanta\Support;

use NoDiscard;

/**
 * The ThrottleVerdict class. What a {@link Throttle} answers an attempt with: let through, or not
 * for so many seconds.
 *
 * **One number carries both halves.** A refusal always has a wait of at least a second and an
 * allowance never has one, so zero is "allowed" and anything above it is "refused, and this long";
 * there is no third state for a pair of fields to drift into, such as allowed with a wait.
 *
 * A value rather than a bool because the refusal's half is what a `Retry-After` is made of, and a
 * caller that got only `false` back would have to ask the throttle again to learn it — a second read
 * of a record another request may have changed in between.
 */
final readonly class ThrottleVerdict
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $retryAfter Seconds until an attempt would be let through; 0 when this one was.
     */
    private function __construct(private int $retryAfter) {}

    /**
     * The attempt was let through, and counted.
     *
     * @return self
     */
    public static function allow(): self
    {
        return new self(0);
    }

    /**
     * The attempt was refused, and one would be let through in $retryAfter seconds.
     *
     * **Never less than one second**, whatever the arithmetic says: a refusal that said "retry in
     * 0" would read as an allowance to {@link self::allowed()}, and as "now" to a client, which then
     * asks again straight away and is refused again.
     *
     * @param int $retryAfter
     * @return self
     */
    public static function refuse(int $retryAfter): self
    {
        return new self(max(1, $retryAfter));
    }

    /**
     * Whether the attempt was let through.
     *
     * @return bool
     */
    #[NoDiscard('this is the whole of the throttle\'s decision, and a dropped one lets the attempt through')]
    public function allowed(): bool
    {
        return $this->retryAfter === 0;
    }

    /**
     * Seconds until an attempt would be let through — 0 when this one was.
     *
     * @return int
     */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
