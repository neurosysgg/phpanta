<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Support\Collection;

/**
 * A readonly value object holding a {@link Collection}, the shape {@link SupportTest} needs to show
 * that readonly protects the reference and immutability protects what it points at.
 */
final readonly class ReadonlyFixture
{
    /**
     * @param Collection $items
     */
    public function __construct(public Collection $items)
    {
    }
}
