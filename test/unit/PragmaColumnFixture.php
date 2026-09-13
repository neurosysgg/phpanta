<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Data\Column;

/**
 * The one column each pragma {@link DatabaseTest} reads back answers under.
 */
enum PragmaColumnFixture: string implements Column
{
    case Timeout     = 'timeout';
    case ForeignKeys = 'foreign_keys';
    case JournalMode = 'journal_mode';
}
