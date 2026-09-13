<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Data\Table;

/**
 * The tables {@link DatabaseTest} builds, the way a site declares its own: a case per table, the
 * value its name.
 */
enum TableFixture: string implements Table
{
    case Authors = 'authors';
    case Notes   = 'notes';

    /** What {@link MigrationFixture::Broken} creates before it fails — so it must never be kept. */
    case Half = 'half';
}
