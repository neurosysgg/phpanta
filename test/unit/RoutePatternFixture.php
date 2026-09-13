<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Support\FillsPlaceholders;
use Phpanta\Support\Path;

/**
 * Patterns holding what a regular expression reads, for {@link RouteTest}: a `.` in a static
 * part, a placeholder followed by one, and the character {@link \Phpanta\Support\Route} delimits
 * its expression with.
 */
enum RoutePatternFixture: string implements Path
{
    use FillsPlaceholders;

    case Feed      = '/feed.xml';
    case Item      = '/items/{slug}.json';
    case Delimited = '/a#b/{slug}';
    case Numbered  = '/items/{id:int}';
    case Tagged    = '/tags/{tag:slug}/{page:int}';
    case Floating  = '/n/{x:float}';
    case Form      = '/form';
}
