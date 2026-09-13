<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Data\Database;
use Phpanta\Data\Migration;
use Phpanta\Data\Sql;

/**
 * Migrations the way a site writes them: an enum, a case per migration, its value the id it is
 * recorded under.
 *
 * None of them says `IF NOT EXISTS`, on purpose: a migration applied twice fails, which is what
 * lets {@link DatabaseTest} prove that none ever is.
 */
enum MigrationFixture: string implements Migration
{
    case CreateAuthors = '2026-09-13-create-authors';
    case CreateNotes   = '2026-09-13-create-notes';
    case IndexNotes    = '2026-09-14-index-notes';

    /** Creates {@link TableFixture::Half}, then fails — so the table must be rolled back with it. */
    case Broken = '2026-09-15-broken';

    /**
     * @return string
     */
    public function id(): string
    {
        return $this->value;
    }

    /**
     * @param Database $database
     * @return void
     */
    public function apply(Database $database): void
    {
        match ($this) {
            self::CreateAuthors => $database->execute(new Sql(sprintf(
                'CREATE TABLE %s (%s INTEGER PRIMARY KEY, %s TEXT NOT NULL)',
                TableFixture::Authors->value,
                AuthorColumnFixture::Id->value,
                AuthorColumnFixture::Name->value,
            ))),
            self::CreateNotes => $database->execute(new Sql(sprintf(
                'CREATE TABLE %s (%s INTEGER PRIMARY KEY, %s INTEGER NOT NULL REFERENCES %s (%s), '
                . '%s TEXT NOT NULL, %s TEXT, %s INTEGER, %s, %s INTEGER NOT NULL DEFAULT 0)',
                TableFixture::Notes->value,
                NoteColumnFixture::Id->value,
                NoteColumnFixture::Author->value,
                TableFixture::Authors->value,
                AuthorColumnFixture::Id->value,
                NoteColumnFixture::Title->value,
                NoteColumnFixture::Body->value,
                NoteColumnFixture::Rating->value,
                NoteColumnFixture::Weight->value,
                NoteColumnFixture::Pinned->value,
            ))),
            self::IndexNotes => $database->execute(new Sql(sprintf(
                'CREATE INDEX notes_by_author ON %s (%s)',
                TableFixture::Notes->value,
                NoteColumnFixture::Author->value,
            ))),
            self::Broken => $database->execute(new Sql('CREATE TABLE ' . TableFixture::Half->value . ' (id INTEGER)'))
                + $database->execute(new Sql('CREATE TABLE ' . TableFixture::Half->value . ' (id INTEGER)')),
        };
    }
}
