<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

/**
 * A count that makes copies of itself — the one thing here declared `: static`, for
 * {@link SupportTest}'s question of what `map()` makes of that return type.
 */
final readonly class CountFixture
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $count
     */
    public function __construct(public int $count) {}

    /**
     * A copy that has counted $more further.
     *
     * @param int $more
     * @return static
     */
    public function plus(int $more): static
    {
        return new static($this->count + $more);
    }
}
