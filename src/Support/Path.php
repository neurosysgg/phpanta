<?php

declare(strict_types=1);

namespace Phpanta\Support;

use BackedEnum;

/**
 * The Path interface. An address, as a case whose value is its route pattern.
 *
 * **The value is the pattern, placeholders and all**, so one case is what {@link Route} matches
 * with *and* what a view builds a link from — one fact, read from one place, in both directions. A
 * pattern that is only ever half of a pair cannot drift from its other half. Two vocabularies
 * implement it, the way two implement {@link \Phpanta\View\Html\TagName}: the framework's own
 * {@link ApiPath}, and each site's own.
 *
 * `{name}` is a placeholder; {@link Route::PLACEHOLDER_PATTERN} is its syntax, and
 * {@link FillsPlaceholders} is the one implementation of {@link self::to()}, so every vocabulary
 * fills its placeholders the same way.
 */
interface Path extends BackedEnum
{
    /**
     * This path with its placeholders filled, in declaration order.
     *
     * @param string ...$values One per placeholder, left to right.
     * @return string
     */
    public function to(string ...$values): string;
}
