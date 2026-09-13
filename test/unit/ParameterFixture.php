<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\Parameter;

/**
 * The parameters the input tests read, as a page would name its own.
 */
enum ParameterFixture: string implements Parameter
{
    case Term      = 'q';
    case Page      = 'page';
    case Loud      = 'loud';
    case Language  = 'lang';
    case Bracketed = 'a[]';
}
