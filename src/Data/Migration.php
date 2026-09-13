<?php

declare(strict_types=1);

namespace Phpanta\Data;

/**
 * The Migration interface. One change to a database's schema, applied once and remembered.
 *
 * **The id is the migration's identity for as long as the database lives.** {@link Migrations}
 * records it when the change is applied and compares it every time after, so an id is never
 * renamed and a migration that has run is never edited or removed: the live database has already
 * been changed by what it said, and the record of that is the id. A change to a change is a new
 * migration.
 *
 * The natural shape is a site's string-backed enum — a case per migration, its value the id, its
 * `apply()` a `match` — so the list is `Migrations(...SiteMigration::cases())`, in the order the
 * cases are written. See `docs/data.md`.
 */
interface Migration
{
    /**
     * What this migration is recorded as. Stable, unique among a site's migrations, never blank.
     *
     * @return string
     */
    public function id(): string;

    /**
     * Makes the change — one {@link Database::execute()} for each statement, since an {@link Sql}
     * holds exactly one.
     *
     * Runs inside a transaction {@link Migrations} has already begun, so it must not begin one of
     * its own; SQLite's schema changes are transactional, and a migration that throws halfway is
     * undone whole, and not recorded.
     *
     * @param Database $database
     * @return void
     */
    public function apply(Database $database): void;
}
