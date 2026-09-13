<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Closure;
use PDO;
use PDOException;
use Phpanta\Data\Binding;
use Phpanta\Data\Database;
use Phpanta\Data\Migration;
use Phpanta\Data\MigrationColumn;
use Phpanta\Data\Migrations;
use Phpanta\Data\MigrationTable;
use Phpanta\Data\Row;
use Phpanta\Data\Sql;
use Phpanta\Exception\CollectionException;
use Phpanta\Exception\DatabaseException;
use Phpanta\Exception\MigrationException;
use Phpanta\Exception\SqlException;
use Phpanta\Model\Health\Area;
use Phpanta\Model\Health\ExtensionRequirement;
use Phpanta\Model\Health\Finding;
use Phpanta\Model\Health\Level;
use Phpanta\Support\Collection;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\SearchableCollection;
use Phpanta\Support\TypedItems;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A database opened one way, read through typed readers, written through bound parameters, changed
 * inside transactions and migrated once — and every way of getting one of those wrong refused where
 * it is written.
 *
 * Every test that touches a database needs pdo_sqlite, and is skipped without it: the framework
 * keeps no database of its own, so a runtime without the driver is not a broken one — which is the
 * whole argument for the requirement being a site's to declare. The statement checks and the
 * requirement run everywhere.
 */
#[CoversClass(Database::class)]
#[CoversClass(Sql::class)]
#[CoversClass(Binding::class)]
#[CoversClass(Row::class)]
#[CoversClass(Migrations::class)]
#[CoversClass(MigrationTable::class)]
#[CoversClass(MigrationColumn::class)]
#[CoversClass(DatabaseException::class)]
#[CoversClass(SqlException::class)]
#[CoversClass(MigrationException::class)]
#[CoversClass(ExtensionRequirement::class)]
#[CoversClass(Finding::class)]
#[CoversClass(Collection::class)]
#[CoversClass(SearchableCollection::class)]
#[CoversTrait(TypedItems::class)]
#[CoversClass(File::class)]
#[CoversClass(Directory::class)]
#[CoversClass(Diagnostics::class)]
final class DatabaseTest extends TestCase
{
    /** A directory of this test's own, for the tests that need a file; removed afterwards. */
    private Directory $directory;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = Directory::temporary('phpanta-database-');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    // ───────────────────────────── reading ─────────────────────────────

    /**
     * Each reader answers the type SQLite stored — and `float()` takes an integer, the one widening
     * the language itself makes.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testEachReaderAnswersTheTypeSqliteStored(): void
    {
        $row = self::row("1 AS id, 'text' AS title, NULL AS body, NULL AS rating, 2.5 AS weight, 1 AS pinned");

        self::assertSame(1, $row->int(NoteColumnFixture::Id));
        self::assertSame('text', $row->string(NoteColumnFixture::Title));
        self::assertSame('text', $row->nullableString(NoteColumnFixture::Title));
        self::assertNull($row->nullableString(NoteColumnFixture::Body));
        self::assertNull($row->nullableInt(NoteColumnFixture::Rating));
        self::assertSame(1, $row->nullableInt(NoteColumnFixture::Id));
        self::assertSame(2.5, $row->float(NoteColumnFixture::Weight));
        self::assertSame(1.0, $row->float(NoteColumnFixture::Id));
        self::assertTrue($row->bool(NoteColumnFixture::Pinned));
        self::assertFalse(self::row('0 AS pinned')->bool(NoteColumnFixture::Pinned));
    }

    /**
     * A reader that finds another kind of value refuses it, rather than converting.
     *
     * @return iterable<string, array{string, Closure(Row): mixed}>
     */
    public static function mismatches(): iterable
    {
        $string         = static fn(Row $row): mixed => $row->string(NoteColumnFixture::Title);
        $nullableString = static fn(Row $row): mixed => $row->nullableString(NoteColumnFixture::Title);
        $int            = static fn(Row $row): mixed => $row->int(NoteColumnFixture::Id);
        $nullableInt    = static fn(Row $row): mixed => $row->nullableInt(NoteColumnFixture::Id);
        $float          = static fn(Row $row): mixed => $row->float(NoteColumnFixture::Weight);
        $bool           = static fn(Row $row): mixed => $row->bool(NoteColumnFixture::Pinned);

        yield 'a number read as text'          => ['1 AS title', $string];
        yield 'NULL read as text'              => ['NULL AS title', $string];
        yield 'a number read as nullable text' => ['1 AS title', $nullableString];
        yield 'text read as an int'            => ["'1' AS id", $int];
        yield 'NULL read as an int'            => ['NULL AS id', $int];
        yield 'a real read as an int'          => ['1.5 AS id', $int];
        yield 'text read as a nullable int'    => ["'four' AS id", $nullableInt];
        yield 'text read as a float'           => ["'2.5' AS weight", $float];
        yield 'NULL read as a float'           => ['NULL AS weight', $float];
        yield 'a two read as a bool'           => ['2 AS pinned', $bool];
        yield 'text read as a bool'            => ["'1' AS pinned", $bool];
        yield 'NULL read as a bool'            => ['NULL AS pinned', $bool];
    }

    /**
     * @param string              $select
     * @param Closure(Row): mixed $read
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    #[DataProvider('mismatches')]
    public function testAReaderRefusesAValueOfAnotherKindRatherThanConvertingIt(string $select, Closure $read): void
    {
        $this->expectException(SqlException::class);
        $this->expectExceptionMessageMatches('/^Column `[a-z]+` holds /');

        $read(self::row($select));
    }

    /**
     * A refusal names the column and the type it found, never the value — a row can hold a
     * password's hash, and the message goes to the log.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testARefusalNamesTheTypeItFoundAndNeverTheValue(): void
    {
        try {
            (void) self::row("'hunter2' AS id")->int(NoteColumnFixture::Id);
            self::fail('A string was read as an int.');
        } catch (SqlException $refused) {
            self::assertStringContainsString('`id` holds string where an int was read', $refused->getMessage());
            self::assertStringNotContainsString('hunter2', $refused->getMessage());
        }
    }

    /**
     * A column the row does not have is refused, naming the ones it does.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAColumnTheRowDoesNotHaveIsRefusedNamingTheOnesItHas(): void
    {
        $this->expectException(SqlException::class);
        $this->expectExceptionMessage('The row has no column `body`; it has `id`, `title`.');

        (void) self::row("1 AS id, 'x' AS title")->string(NoteColumnFixture::Body);
    }

    /**
     * Both `id`s of a join are kept, and reading one is refused — `FETCH_ASSOC` would have kept the
     * second and answered with it.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAColumnAJoinNamesTwiceIsRefusedRatherThanWonByTheLastOne(): void
    {
        $database = $this->notes();
        self::note($database, 'first');

        $row = $database->first(
            new Sql('SELECT authors.id, notes.id FROM authors JOIN notes ON notes.author = authors.id LIMIT 1'),
            static fn(Row $row): Row => $row,
        );

        self::assertInstanceOf(Row::class, $row);
        $this->expectException(SqlException::class);
        $this->expectExceptionMessage('The row has 2 columns named `id`');

        (void) $row->int(AuthorColumnFixture::Id);
    }

    // ───────────────────────────── binding ─────────────────────────────

    /**
     * A value is bound, never written into the statement: a string shaped like an injection is
     * stored and read back as exactly itself, and the table it names is still there.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAValueIsBoundAndNeverWrittenIntoTheStatement(): void
    {
        $database = $this->notes();
        $hostile  = "x'); DROP TABLE notes; -- :title ?";

        self::note($database, $hostile);

        self::assertSame(
            [$hostile],
            $database->select(
                new Sql('SELECT title FROM notes WHERE title = :title', title: $hostile),
                static fn(Row $row): string => $row->string(NoteColumnFixture::Title),
            )->toValues(),
        );
    }

    /**
     * A float is stored as a real even in a column with no type, and reads back as exactly itself —
     * bound as text it would come back a string, and written with PHP's own `precision` it would
     * come back rounded.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAFloatIsStoredAsARealEvenInAColumnWithNoType(): void
    {
        $database = $this->notes();

        foreach ([0.1 + 0.2, -0.5, 3.0, 1.0e300, 5.0e-324] as $weight) {
            $database->execute(new Sql(
                'INSERT INTO notes (author, title, weight) VALUES (1, :title, :weight)',
                title: 'weighed',
                weight: $weight,
            ));

            $read = $database->first(
                new Sql(
                    'SELECT weight, typeof(weight) AS kind FROM notes WHERE id = :id',
                    id: $database->lastInsertId(),
                ),
                static fn(Row $row): array => [
                    $row->float(NoteColumnFixture::Weight),
                    $row->string(NoteColumnFixture::Kind),
                ],
            );

            self::assertSame([$weight, 'real'], $read);
        }
    }

    /**
     * A bool is stored as SQLite stores one — the integer 1 or 0 — and read back as a bool.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testABoolIsStoredAsOneOrZero(): void
    {
        $database = $this->notes();

        foreach ([1 => true, 0 => false] as $stored => $pinned) {
            $database->execute(new Sql(
                'INSERT INTO notes (author, title, weight, pinned) VALUES (1, :title, :weight, :pinned)',
                title: 'flagged',
                weight: null,
                pinned: $pinned,
            ));

            $read = $database->first(
                new Sql(
                    'SELECT pinned, typeof(pinned) AS kind FROM notes WHERE id = :id',
                    id: $database->lastInsertId(),
                ),
                static fn(Row $row): array => [
                    $row->int(NoteColumnFixture::Pinned),
                    $row->string(NoteColumnFixture::Kind),
                    $row->bool(NoteColumnFixture::Pinned),
                ],
            );

            self::assertSame([$stored, 'integer', $pinned], $read);
        }
    }

    /**
     * A statement and its parameters are compared where the statement is written, and each way
     * they can disagree — or bind silently — is refused there.
     *
     * @return iterable<string, array{Closure(): Sql, string}>
     */
    public static function refusedStatements(): iterable
    {
        yield 'a placeholder no parameter fills' => [
            static fn(): Sql => new Sql('SELECT * FROM notes WHERE id = :id'),
            'no parameter fills :id',
        ];
        yield 'a parameter no placeholder reads' => [
            static fn(): Sql => new Sql('SELECT 1', id: 1),
            'no placeholder reads id:',
        ];
        yield 'a misspelled parameter' => [
            static fn(): Sql => new Sql('SELECT :id', di: 1),
            'no parameter fills :id; no placeholder reads di:',
        ];
        yield 'a positional parameter' => [
            static fn(): Sql => new Sql('SELECT :id', 1),
            'Parameter 1 of `SELECT :id` has no name',
        ];
        yield 'a question mark' => [
            static fn(): Sql => new Sql('SELECT * FROM notes WHERE id = ?'),
            'writes the placeholder `?`',
        ];
        yield 'a numbered question mark' => [static fn(): Sql => new Sql('SELECT ?1'), 'writes the placeholder `?1`'];
        yield 'an at-sign placeholder'   => [static fn(): Sql => new Sql('SELECT @id'), 'writes the placeholder `@id`'];
        yield 'a dollar placeholder'     => [static fn(): Sql => new Sql('SELECT $id'), 'writes the placeholder `$id`'];
        yield 'two statements'           => [
            static fn(): Sql => new Sql('DELETE FROM notes; DELETE FROM authors'),
            'is more than one statement',
        ];
        yield 'an infinite float' => [static fn(): Sql => new Sql('SELECT :x', x: INF), 'is not a finite number'];
        yield 'not a number'      => [static fn(): Sql => new Sql('SELECT :x', x: NAN), 'is not a finite number'];
        yield 'nothing'           => [static fn(): Sql => new Sql("  \n"), 'A statement needs some SQL in it.'];
    }

    /**
     * Refused without a database at all: the check is the constructor's.
     *
     * @param Closure(): Sql $build
     * @param string         $message
     * @return void
     */
    #[DataProvider('refusedStatements')]
    public function testAStatementAndItsParametersAreComparedWhereItIsWritten(Closure $build, string $message): void
    {
        $this->expectException(SqlException::class);
        $this->expectExceptionMessage($message);

        $build();
    }

    /**
     * What looks like a placeholder inside a literal, a quoted identifier or a comment is text, as
     * SQLite reads it; one placeholder may be read twice; and a trailing `;` is one statement.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testWhatLooksLikeAPlaceholderInsideALiteralOrACommentIsText(): void
    {
        $sql = new Sql(
            "SELECT ':not; ?', \"b:c\" -- :nor ?\n, :a + :a AS id /* :this; */ "
            . "FROM (SELECT 1 AS \"b:c\", 2 AS [e:f]);\n-- done",
            a: 2,
        );

        self::assertSame(
            4,
            Database::inMemory()->first($sql, static fn(Row $row): int => $row->int(NoteColumnFixture::Id)),
        );
    }

    /**
     * A statement too tangled for the scan to finish is refused, rather than handed to SQLite
     * unscanned.
     *
     * @return void
     */
    public function testAStatementTheScanCannotFinishIsRefused(): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        $jit   = ini_get('pcre.jit');

        ini_set('pcre.backtrack_limit', '1');
        ini_set('pcre.jit', '0');

        try {
            $this->expectException(SqlException::class);
            $this->expectExceptionMessage('could not be scanned for placeholders: Backtrack limit exhausted.');

            (void) new Sql('SELECT 1 /* ' . str_repeat('x', 100) . ' */');
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            ini_set('pcre.jit', (string) $jit);
        }
    }

    // ───────────────────────────── selecting and writing ─────────────────────────────

    /**
     * `select()` hands every row to the mapper and answers with what it returned, typed by the
     * mapper's own return declaration; `first()` answers with the first, or null.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testSelectMapsEveryRowAndFirstTheFirstOrNull(): void
    {
        $database = $this->notes();

        self::note($database, 'first', 'with a body');
        self::note($database, 'second');
        self::note($database, 'third');

        $note  = static fn(Row $row): NoteFixture => new NoteFixture(
            $row->int(NoteColumnFixture::Id),
            $row->string(NoteColumnFixture::Title),
            $row->nullableString(NoteColumnFixture::Body),
        );
        $notes = $database->select(new Sql('SELECT id, title, body FROM notes ORDER BY id'), $note);

        self::assertSame(NoteFixture::class, $notes->type);
        self::assertEquals(
            [
                new NoteFixture(1, 'first', 'with a body'),
                new NoteFixture(2, 'second', null),
                new NoteFixture(3, 'third', null),
            ],
            $notes->toValues(),
        );
        self::assertSame($notes, $notes->settled(), 'select() leaves nothing pending: the mapper has run');

        $none = $database->select(new Sql('SELECT id, title, body FROM notes WHERE id > :id', id: 3), $note);
        self::assertTrue($none->isEmpty());
        self::assertSame(NoteFixture::class, $none->type);

        self::assertEquals(
            new NoteFixture(2, 'second', null),
            $database->first(new Sql('SELECT id, title, body FROM notes WHERE id = :id', id: 2), $note),
        );
        self::assertNull($database->first(new Sql('SELECT id, title, body FROM notes WHERE id = :id', id: 9), $note));
    }

    /**
     * A mapper that does not say what it returns is refused, as `Collection::map()` refuses one.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAMapperWithNoReturnTypeIsRefused(): void
    {
        $this->expectException(CollectionException::class);

        (void) Database::inMemory()->select(
            new Sql('SELECT 1 AS id'),
            static fn(Row $row) => $row->int(NoteColumnFixture::Id),
        );
    }

    /**
     * `execute()` answers how many rows it changed, and `lastInsertId()` which row it wrote.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testExecuteCountsTheRowsItChangedAndLastInsertIdNamesTheRow(): void
    {
        $database = $this->notes();

        self::assertSame(1, self::note($database, 'first'));
        self::assertSame(1, $database->lastInsertId());
        self::note($database, 'second');
        self::assertSame(2, $database->lastInsertId());

        self::assertSame(2, $database->execute(new Sql('UPDATE notes SET rating = :rating', rating: 5)));
        self::assertSame(0, $database->execute(new Sql('DELETE FROM notes WHERE id = :id', id: 9)));
    }

    /**
     * Foreign keys are enforced — SQLite's own default is to ignore them.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testForeignKeysAreEnforced(): void
    {
        $database = $this->notes();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');

        $database->execute(new Sql(
            'INSERT INTO notes (author, title) VALUES (:author, :title)',
            author: 99,
            title: 'orphan',
        ));
    }

    // ───────────────────────────── transactions ─────────────────────────────

    /**
     * A transaction commits what its work wrote, and answers with what the work returned.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testATransactionCommitsWhatItsWorkWrote(): void
    {
        $database = $this->notes();

        $answer = $database->transaction(static function (Database $database): string {
            self::note($database, 'first');
            self::note($database, 'second');

            return 'done';
        });

        self::assertSame('done', $answer);
        self::assertSame(2, self::notesIn($database));
    }

    /**
     * A transaction whose work throws is rolled back, and what the work threw arrives unchanged —
     * the same object, not a wrapper around it — and the connection is free for the next one.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testATransactionWhoseWorkThrowsRollsBackAndRethrowsTheOriginal(): void
    {
        $database = $this->notes();
        $thrown   = new RuntimeException('the work failed');

        try {
            $database->transaction(static function (Database $database) use ($thrown): never {
                self::note($database, 'kept only until the throw');

                throw $thrown;
            });
            self::fail('The transaction swallowed what its work threw.');
        } catch (RuntimeException $caught) {
            self::assertSame($thrown, $caught);
        }

        self::assertSame(0, self::notesIn($database));

        $database->transaction(static fn(Database $database): int => self::note($database, 'after'));
        self::assertSame(1, self::notesIn($database));
    }

    /**
     * A commit that fails — a deferred foreign key, checked only at `COMMIT` — is rolled back too.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testATransactionWhoseCommitFailsIsRolledBack(): void
    {
        $database = $this->notes();
        $database->execute(new Sql(
            'CREATE TABLE later (author INTEGER REFERENCES authors (id) DEFERRABLE INITIALLY DEFERRED)',
        ));

        try {
            $database->transaction(static fn(Database $database): int => $database->execute(
                new Sql('INSERT INTO later (author) VALUES (:author)', author: 99),
            ));
            self::fail('A commit that broke a foreign key succeeded.');
        } catch (PDOException $refused) {
            self::assertStringContainsString('FOREIGN KEY constraint failed', $refused->getMessage());
        }

        self::assertSame(0, $database->first(
            new Sql('SELECT count(*) AS id FROM later'),
            static fn(Row $row): int => $row->int(NoteColumnFixture::Id),
        ));
        self::assertSame(
            1,
            $database->transaction(static fn(Database $database): int => self::note($database, 'after')),
        );
    }

    /**
     * A transaction inside another is refused, and the refusal rolls the outer one back with it.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testATransactionInsideAnotherIsRefused(): void
    {
        $database = $this->notes();

        try {
            $database->transaction(static function (Database $database): int {
                self::note($database, 'outer');

                return $database->transaction(
                    static fn(Database $database): int => self::note($database, 'inner'),
                );
            });
            self::fail('A transaction was begun inside another.');
        } catch (SqlException $refused) {
            self::assertStringContainsString(
                'A transaction was begun inside another on :memory:',
                $refused->getMessage(),
            );
        }

        self::assertSame(0, self::notesIn($database));
    }

    // ───────────────────────────── migrations ─────────────────────────────

    /**
     * Migrations apply once each, in the order given, and record themselves in that order; a list
     * that grows applies only what it grew by.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testMigrationsApplyOnceInOrderAndRecordThemselves(): void
    {
        $database = Database::inMemory();
        $both     = [MigrationFixture::CreateAuthors, MigrationFixture::CreateNotes];
        $first    = new Migrations(...$both);

        self::assertSame($both, $first->pending($database)->toValues());
        self::assertSame($both, $first->apply($database)->toValues());

        // None of the fixtures says IF NOT EXISTS, so a second application would throw.
        self::assertTrue($first->apply($database)->isEmpty());
        self::assertTrue($first->pending($database)->isEmpty());

        $grown = new Migrations(...$both, ...[MigrationFixture::IndexNotes]);
        self::assertSame([MigrationFixture::IndexNotes], $grown->apply($database)->toValues());

        $history = $database->select(
            new Sql(sprintf(
                'SELECT %s, %s, %s FROM %s ORDER BY %s',
                MigrationColumn::Position->value,
                MigrationColumn::Id->value,
                MigrationColumn::AppliedAt->value,
                MigrationTable::Migrations->value,
                MigrationColumn::Position->value,
            )),
            static fn(Row $row): string => $row->int(MigrationColumn::Position)
                . ' ' . $row->string(MigrationColumn::Id)
                . ($row->int(MigrationColumn::AppliedAt) > 0 ? '' : ' never'),
        );

        self::assertSame(
            ['1 2026-09-13-create-authors', '2 2026-09-13-create-notes', '3 2026-09-14-index-notes'],
            $history->toValues(),
        );
    }

    /**
     * An empty list against a database with no history does nothing — not even make the table.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testNothingPendingWritesNothing(): void
    {
        $database = Database::inMemory();

        self::assertTrue(new Migrations()->apply($database)->isEmpty());
        self::assertFalse(self::hasTable($database, MigrationTable::Migrations->value));
    }

    /**
     * A migration that throws is undone whole — the table it made is gone — and not recorded, so
     * it is still pending; what ran before it stays applied.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAMigrationThatThrowsIsUndoneAndNotRecorded(): void
    {
        $database   = Database::inMemory();
        $migrations = new Migrations(MigrationFixture::CreateAuthors, MigrationFixture::Broken);

        try {
            (void) $migrations->apply($database);
            self::fail('A migration that throws was applied.');
        } catch (PDOException $refused) {
            self::assertStringContainsString('table half already exists', $refused->getMessage());
        }

        self::assertFalse(self::hasTable($database, TableFixture::Half->value));
        self::assertTrue(self::hasTable($database, TableFixture::Authors->value));
        self::assertSame([MigrationFixture::Broken], $migrations->pending($database)->toValues());
    }

    /**
     * A list whose recorded history is not a prefix of it is refused, before anything runs.
     *
     * @return iterable<string, array{Closure(): Migrations, string}>
     */
    public static function rewrittenHistories(): iterable
    {
        $renamed = new class () implements Migration {
            /**
             * @return string
             */
            public function id(): string
            {
                return '2026-09-13-create-notes-v2';
            }

            /**
             * @param Database $database
             * @return void
             */
            public function apply(Database $database): void {}
        };

        yield 'renamed' => [
            static fn(): Migrations => new Migrations(MigrationFixture::CreateAuthors, $renamed),
            'records `2026-09-13-create-notes` as migration 2, and the list has `2026-09-13-create-notes-v2` there',
        ];
        yield 'moved' => [
            static fn(): Migrations => new Migrations(MigrationFixture::CreateNotes, MigrationFixture::CreateAuthors),
            'records `2026-09-13-create-authors` as migration 1, and the list has `2026-09-13-create-notes` there',
        ];
        yield 'removed from the end' => [
            static fn(): Migrations => new Migrations(MigrationFixture::CreateAuthors),
            'records `2026-09-13-create-notes` as migration 2, and the list has only 1',
        ];
        yield 'removed from the middle' => [
            static fn(): Migrations => new Migrations(MigrationFixture::CreateNotes, MigrationFixture::IndexNotes),
            'records `2026-09-13-create-authors` as migration 1',
        ];
    }

    /**
     * @param Closure(): Migrations $rewritten
     * @param string                $message
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    #[DataProvider('rewrittenHistories')]
    public function testARewrittenHistoryIsRefused(Closure $rewritten, string $message): void
    {
        $database = Database::inMemory();
        (void) new Migrations(MigrationFixture::CreateAuthors, MigrationFixture::CreateNotes)->apply($database);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage(':memory: ' . $message);

        (void) $rewritten()->apply($database);
    }

    /**
     * A list that names one id twice, or a migration with none, is refused where it is written.
     *
     * @return void
     */
    public function testAListNamingAnIdTwiceOrNoneIsRefused(): void
    {
        try {
            (void) new Migrations(MigrationFixture::CreateAuthors, MigrationFixture::CreateAuthors);
            self::fail('Two migrations shared an id.');
        } catch (MigrationException $refused) {
            self::assertSame(
                'Two migrations are both `2026-09-13-create-authors`, and a history records each id once.',
                $refused->getMessage(),
            );
        }

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('A migration needs an id to be recorded under');

        (void) new Migrations(new class () implements Migration {
            /**
             * @return string
             */
            public function id(): string
            {
                return ' ';
            }

            /**
             * @param Database $database
             * @return void
             */
            public function apply(Database $database): void {}
        });
    }

    // ───────────────────────────── opening ─────────────────────────────

    /**
     * Every connection is opened the same way: a five-second wait for a lock, foreign keys on, and a
     * rollback journal on a file.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testEveryConnectionIsConfiguredTheSameWay(): void
    {
        $file     = $this->directory->file('site.sqlite');
        $database = Database::openOrCreate($file);

        self::assertSame(5000, $database->first(
            new Sql('PRAGMA busy_timeout'),
            static fn(Row $row): int => $row->int(PragmaColumnFixture::Timeout),
        ));
        self::assertTrue($database->first(
            new Sql('PRAGMA foreign_keys'),
            static fn(Row $row): bool => $row->bool(PragmaColumnFixture::ForeignKeys),
        ));
        self::assertSame('delete', self::journalOf($database));
        self::assertSame($file->path, $database->name);
    }

    /**
     * A file some tool left in WAL is put back to a rollback journal when it is opened.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAFileLeftInWalIsPutBack(): void
    {
        $file = $this->directory->file('site.sqlite');
        $tool = new PDO('sqlite:' . $file->path);
        $tool->exec('PRAGMA journal_mode = WAL');
        $tool->exec('CREATE TABLE t (x)');
        $tool->exec('INSERT INTO t VALUES (1)');
        unset($tool);

        self::assertSame('delete', self::journalOf(Database::open($file)));
    }

    /**
     * A file another connection is holding in WAL is refused rather than used in it.
     *
     * Its own test, and nothing after the refusal needs the tool's connection gone: Xdebug's develop
     * mode keeps the frames an exception was thrown through, locals and all, until the next one is
     * thrown — so a reopen here would find the file still held by a connection this test let go of.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAFileHeldInWalIsRefused(): void
    {
        $file = $this->directory->file('site.sqlite');
        $tool = new PDO('sqlite:' . $file->path);
        $tool->exec('PRAGMA journal_mode = WAL');
        $tool->exec('CREATE TABLE t (x)');
        $tool->exec('INSERT INTO t VALUES (1)');
        $reading = $tool->query('SELECT x FROM t');
        (void) $reading->fetch();

        try {
            (void) Database::open($file);
            self::fail('A database held in WAL was opened.');
        } catch (DatabaseException $refused) {
            self::assertStringContainsString(
                'The database at ' . $file->path . ' cannot be opened: ',
                $refused->getMessage(),
            );
            self::assertInstanceOf(PDOException::class, $refused->getPrevious());
        }
    }

    /**
     * `open()` refuses a file that is not there, and makes none; `openOrCreate()` makes the file,
     * and never its directory.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testOpenMakesNothingAndOpenOrCreateMakesTheFileButNeverItsDirectory(): void
    {
        $file = $this->directory->file('site.sqlite');

        try {
            (void) Database::open($file);
            self::fail('open() made a database.');
        } catch (DatabaseException $refused) {
            self::assertSame(
                'There is no database at ' . $file->path
                . '. Database::open() never makes one; openOrCreate() does.',
                $refused->getMessage(),
            );
        }

        self::assertFalse($file->exists());

        (void) Database::openOrCreate($file);
        self::assertTrue($file->exists());

        $missing = $this->directory->directory('missing');

        try {
            (void) Database::openOrCreate($missing->file('site.sqlite'));
            self::fail('openOrCreate() made a directory, or a file in no directory.');
        } catch (DatabaseException $refused) {
            self::assertStringContainsString($missing->path . '/site.sqlite cannot be opened', $refused->getMessage());
            self::assertInstanceOf(PDOException::class, $refused->getPrevious());
        }

        self::assertFalse($missing->exists());
    }

    /**
     * A file that is not a database opens — SQLite opens lazily — and is refused at the first
     * pragma that reads it, still naming the file.
     *
     * @return void
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAFileThatIsNotADatabaseIsRefused(): void
    {
        $file = $this->directory->file('notes.txt');
        self::assertTrue($file->write(str_repeat('not a database. ', 64)));

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('The database at ' . $file->path . ' cannot be opened: ');

        (void) Database::open($file);
    }

    // ───────────────────────────── the requirement ─────────────────────────────

    /**
     * What a site declares when it keeps a database: `pdo_sqlite`, required, proved by working —
     * and met exactly where this PHP has it. Runs without the extension too.
     *
     * @return void
     */
    public function testTheRequirementAsksForPdoSqliteByUsingIt(): void
    {
        $requirement = Database::requirement();

        self::assertSame('pdo_sqlite', $requirement->name());
        self::assertSame(Area::Extensions, $requirement->area());
        self::assertSame(Level::Required, $requirement->level());
        self::assertSame('working', $requirement->expected());
        self::assertSame(extension_loaded('pdo_sqlite'), $requirement->check()->met);
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /**
     * One row of literals, selected from no table at all.
     *
     * @param string $select What follows `SELECT`.
     * @return Row
     */
    private static function row(string $select): Row
    {
        return Database::inMemory()->first(new Sql('SELECT ' . $select), static fn(Row $row): Row => $row)
            ?? self::fail('The select read no row.');
    }

    /**
     * A database with the notes schema and one author, id 1.
     *
     * @return Database
     */
    private function notes(): Database
    {
        $database = Database::inMemory();

        (void) new Migrations(MigrationFixture::CreateAuthors, MigrationFixture::CreateNotes)->apply($database);
        $database->execute(new Sql('INSERT INTO authors (name) VALUES (:name)', name: 'Ada'));

        return $database;
    }

    /**
     * Writes one note by author 1.
     *
     * @param Database    $database
     * @param string      $title
     * @param string|null $body
     * @return int The rows written.
     */
    private static function note(Database $database, string $title, ?string $body = null): int
    {
        return $database->execute(new Sql(
            'INSERT INTO notes (author, title, body) VALUES (:author, :title, :body)',
            author: 1,
            title: $title,
            body: $body,
        ));
    }

    /**
     * How many notes there are.
     *
     * @param Database $database
     * @return int
     */
    private static function notesIn(Database $database): int
    {
        return $database->first(
            new Sql('SELECT count(*) AS id FROM notes'),
            static fn(Row $row): int => $row->int(NoteColumnFixture::Id),
        ) ?? 0;
    }

    /**
     * Whether the database has a table of that name.
     *
     * @param Database $database
     * @param string   $name
     * @return bool
     */
    private static function hasTable(Database $database, string $name): bool
    {
        return $database->first(
            new Sql('SELECT 1 FROM sqlite_master WHERE type = \'table\' AND name = :name', name: $name),
            static fn(Row $row): bool => true,
        ) ?? false;
    }

    /**
     * The journal mode the database is in.
     *
     * @param Database $database
     * @return string
     */
    private static function journalOf(Database $database): string
    {
        return $database->first(
            new Sql('PRAGMA journal_mode'),
            static fn(Row $row): string => $row->string(PragmaColumnFixture::JournalMode),
        ) ?? '';
    }
}
