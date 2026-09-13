<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Support\FillsPlaceholders;
use Phpanta\Support\Path;

/**
 * The addresses {@link RouteExportTest} and {@link ExportTest} build routes on: one of each shape a
 * route can have, and the one address an export keeps for itself.
 */
enum ExportFixturePath: string implements Path
{
    use FillsPlaceholders;

    case Home    = '/';
    case Guide   = '/guide';
    case Page    = '/pages/{slug}';
    case Pair    = '/pairs/{left}/{right}';
    case Missing = '/404';
}
