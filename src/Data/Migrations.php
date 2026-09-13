<?php

declare(strict_types=1);

namespace Phpanta\Data;

use NoDiscard;
use Phpanta\Exception\MigrationException;
use Phpanta\Support\BareString;
use Phpanta\Support\Collection;

/**
 * The Migrations class. A site's migrations, in the order they are to be applied, and the runner
 * that applies the ones a database has not had yet.
 *
 * **What has run is recorded in the database it ran on**, in {@link MigrationTable::Migrations}:
 * each id, in the order applied. That record is the only account of what the live schema has been
 * through, so it is the list that has to agree with it, never the other way round.
 *
 * **The record must be a prefix of the list, or nothing runs.** An applied id missing from its
 * place means a migration that already changed the live database was renamed, removed or moved,
 * and applying "the rest" of a list that has been rewritten underneath its history is how two
 * databases built from the same code end up with different schemas. A {@link MigrationException}
 * says where the two part.
 *
 * **Each migration runs in its own transaction, and is recorded in the same one.** A migration that
 * throws leaves no half-changed schema and no record, and the next run tries it again. Which
 * migration a transaction applies is decided inside it, by the history as read under its lock —
 * each begins `IMMEDIATE`, see {@link Database::transaction()} — so two requests arriving at once on
 * a fresh deployment apply each migration once between them: the second waits for the first's
 * lock, then finds the id recorded and moves on to the next.
 *
 * **Cheap when there is nothing to do**, so a site may call {@link self::apply()} wherever it opens
 * its database: with every migration recorded it is one read of `sqlite_master` and one of the
 * history, no write and no lock.
 */
#[BareString(
    'string',
    "get_debug_type()'s spelling in a class-string's place, the coincidence TypedItems and "
    . 'Diagnostics are excused for: the empty history of a database that has none yet, a collection '
    . 'of ids. No vocabulary spells a scalar type name for it to have been read off.',
)]
final readonly class Migrations
{
    /**
     * In the order given, which is the order they are applied in.
     *
     * @var Collection<Migration>
     */
    private Collection $migrations;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Migration ...$migrations In the order they are to be applied.
     *
     * @throws MigrationException if an id is blank, or two migrations share one.
     */
    public function __construct(Migration ...$migrations)
    {
        $seen = [];

        foreach ($migrations as $migration) {
            $id = $migration->id();

            if (trim($id) === '') {
                throw new MigrationException(
                    'A migration needs an id to be recorded under; ' . $migration::class . ' has none.',
                );
            }

            if (isset($seen[$id])) {
                throw new MigrationException(sprintf(
                    'Two migrations are both `%s`, and a history records each id once.',
                    $id,
                ));
            }

            $seen[$id] = true;
        }

        $this->migrations = new Collection(Migration::class)->with(...$migrations);
    }

    /**
     * The migrations $database has not had yet, in the order they would be applied.
     *
     * @param Database $database
     * @return Collection<Migration>
     *
     * @throws MigrationException if the recorded history is not a prefix of this list.
     */
    #[NoDiscard('pending() answers a question and changes nothing, so a call whose result goes nowhere does nothing')]
    public function pending(Database $database): Collection
    {
        $applied = $this->history($database)->count();

        return $this->migrations
            ->where(static fn(Migration $migration, int $position): bool => $position >= $applied)
            ->settled();
    }

    /**
     * Applies every pending migration to $database, each in a transaction of its own, and records it.
     *
     * @param Database $database
     * @return Collection<Migration> The migrations this call applied — none where another request
     *                               got to them first.
     *
     * @throws MigrationException if the recorded history is not a prefix of this list.
     */
    public function apply(Database $database): Collection
    {
        $pending = $this->pending($database);
        $applied = [];

        if ($pending->isEmpty()) {
            return new Collection(Migration::class);
        }

        $database->execute(new Sql(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (%s INTEGER PRIMARY KEY, %s TEXT NOT NULL UNIQUE, %s INTEGER NOT NULL)',
            MigrationTable::Migrations->value,
            MigrationColumn::Position->value,
            MigrationColumn::Id->value,
            MigrationColumn::AppliedAt->value,
        )));

        while (($migration = $database->transaction($this->applyNext(...))) !== null) {
            $applied[] = $migration;
        }

        return new Collection(Migration::class)->with(...$applied);
    }

    /**
     * Applies and records whichever migration is next by the history as $database records it now —
     * inside a transaction, so that is the history as it stands under the write lock.
     *
     * Next by the history rather than next in a list worked out before the lock was taken: a
     * request that waited while another applied what it was about to apply finds the id already
     * recorded, and moves on to the one after it, or to none.
     *
     * @param Database $database
     * @return Migration|null What it applied, or null when nothing is pending.
     */
    private function applyNext(Database $database): ?Migration
    {
        $migration = $this->pending($database)->first();

        if ($migration === null) {
            return null;
        }

        $migration->apply($database);

        $database->execute(new Sql(
            sprintf(
                'INSERT INTO %s (%s, %s) VALUES (:id, :at)',
                MigrationTable::Migrations->value,
                MigrationColumn::Id->value,
                MigrationColumn::AppliedAt->value,
            ),
            id: $migration->id(),
            at: time(),
        ));

        return $migration;
    }

    /**
     * The ids $database records as applied, in order — none where it has no history yet — having
     * checked that they are a prefix of this list.
     *
     * @param Database $database
     * @return Collection<string>
     *
     * @throws MigrationException if they are not.
     */
    private function history(Database $database): Collection
    {
        $kept = $database->first(
            new Sql(
                'SELECT 1 FROM sqlite_master WHERE type = \'table\' AND name = :name',
                name: MigrationTable::Migrations->value,
            ),
            static fn(Row $row): bool => true,
        );

        if ($kept === null) {
            return new Collection('string');
        }

        $history = $database->select(
            new Sql(sprintf(
                'SELECT %s FROM %s ORDER BY %s',
                MigrationColumn::Id->value,
                MigrationTable::Migrations->value,
                MigrationColumn::Position->value,
            )),
            static fn(Row $row): string => $row->string(MigrationColumn::Id),
        );

        $listed = $this->migrations->map(static fn(Migration $migration): string => $migration->id())->toValues();

        foreach ($history as $position => $id) {
            $expected = $listed[$position] ?? null;

            if ($expected === null) {
                throw new MigrationException(sprintf(
                    '%s records `%s` as migration %d, and the list has only %d. An applied migration '
                    . 'was removed; put it back, and undo it with a new one if that is what was meant.',
                    $database->name,
                    $id,
                    $position + 1,
                    count($listed),
                ));
            }

            if ($expected !== $id) {
                throw new MigrationException(sprintf(
                    '%s records `%s` as migration %d, and the list has `%s` there. An applied migration '
                    . 'was renamed, removed or moved; put it back where it was, and make the change as a '
                    . 'new migration at the end.',
                    $database->name,
                    $id,
                    $position + 1,
                    $expected,
                ));
            }
        }

        return $history;
    }
}
