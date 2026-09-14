<?php

declare(strict_types=1);

namespace Phpanta\Data;

/**
 * The ForeignKeyCheckColumn enum. The columns of SQLite's `pragma_foreign_key_check`, one row per
 * reference that names a row that is not there — which {@link Migrations} asks before each migration
 * commits, since migrations run with foreign keys off.
 */
enum ForeignKeyCheckColumn: string implements Column
{
    /** The table holding the reference. */
    case Table = 'table';

    /** The table it refers to. */
    case Parent = 'parent';
}
