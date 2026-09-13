<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\SessionKey;

/**
 * The keys the session tests keep values under, as a site would name its own — and one that
 * collides with the framework's.
 */
enum SessionKeyFixture: string implements SessionKey
{
    case Cart     = 'cart';
    case Reserved = '_user';
}
