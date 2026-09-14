<?php

declare(strict_types=1);

namespace Hello;

use Phpanta\Support\FillsPlaceholders;
use Phpanta\Support\Path;

/**
 * Every address the example answers on: the route matches the value, and a link fills it in.
 */
enum HelloPath: string implements Path
{
    use FillsPlaceholders;

    case World   = '/';
    case Someone = '/hello/{name}';
}
