<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Support\FillsPlaceholders;
use Phpanta\Support\Path;

/**
 * The addresses {@link RouteExportTest} builds routes on: one of each shape a route can have.
 */
enum ExportFixturePath: string implements Path
{
    use FillsPlaceholders;

    case Home  = '/';
    case Guide = '/guide';
    case Page  = '/pages/{slug}';
    case Pair  = '/pairs/{left}/{right}';
}
