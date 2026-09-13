<?php

declare(strict_types=1);

namespace Phpanta\Data;

/**
 * The MigrationTable enum. The one table the framework keeps in a site's database: the history of
 * the migrations {@link Migrations} has applied to it.
 *
 * Prefixed with the framework's name, because it lives in a database whose every other table is
 * the site's, and a site with a table of its own called `migrations` is not a far-fetched site.
 */
enum MigrationTable: string implements Table
{
    case Migrations = 'phpanta_migrations';
}
