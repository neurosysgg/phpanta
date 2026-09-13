<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Support\FillsPlaceholders;
use Phpanta\Support\Path;

/**
 * The login recipe's three addresses — see docs/login.md.
 */
enum LoginPathFixture: string implements Path
{
    use FillsPlaceholders;

    case Login   = '/login';
    case Logout  = '/logout';
    case Account = '/account';
}
