<?php

declare(strict_types=1);

namespace Phpanta\Data;

/**
 * The MigrationColumn enum. The columns of {@link MigrationTable::Migrations}.
 */
enum MigrationColumn: string implements Column
{
    /**
     * The order it was applied in — the table's `INTEGER PRIMARY KEY`, so SQLite numbers it, and
     * the history is read back in this order rather than in whatever order a scan happens to find.
     */
    case Position = 'position';

    /** {@link Migration::id()}, unique. */
    case Id = 'id';

    /** When, as a Unix timestamp — for a person reading the table, since nothing here asks. */
    case AppliedAt = 'applied_at';
}
