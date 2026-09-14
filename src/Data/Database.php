<?php

declare(strict_types=1);

namespace Phpanta\Data;

use Closure;
use NoDiscard;
use PDO;
use PDOException;
use PDOStatement;
use Phpanta\Exception\DatabaseException;
use Phpanta\Exception\SqlException;
use Phpanta\Model\Health\ExtensionRequirement;
use Phpanta\Model\Health\Level;
use Phpanta\Support\Collection;
use Phpanta\Support\File;

/**
 * The Database class. One SQLite connection, opened the one way this framework opens one.
 *
 * **SQLite because a shared host has it and nothing to run.** The database is a file beside the
 * site's other data, reached through PHP's own extension; there is no server to keep up, no
 * credentials to deploy, and no runtime dependency beyond pdo_sqlite. A site that keeps a database
 * declares that extension with {@link self::requirement()}; a site that keeps none needs nothing,
 * which is why it is not in the framework's floor.
 *
 * Every connection is opened with the same settings, and each one is there because its default
 * fails quietly:
 *
 * - **Errors are exceptions**, so a failed statement is never a `false` nobody checked — and each
 *   one leaves here a {@link DatabaseException} naming the statement, the driver's own kept as its
 *   cause: a `PDOException` is no {@link \Phpanta\Exception\SiteException}, and none gets out.
 * - **Prepares are the driver's**, not PDO's emulation, so a value is bound and never spliced into
 *   the text; and **values come back typed**, not stringified, which {@link Row}'s readers depend on.
 * - **Rows are fetched `FETCH_NAMED`**, so a column two joined tables both name is kept twice and
 *   refused on reading, rather than one of them silently winning.
 * - **Foreign keys are enforced** — SQLite's default is to parse a `REFERENCES` and ignore it, per
 *   connection. A build of SQLite without them ignores the request as well, and a schema whose
 *   constraints do nothing is exactly the silent failure; {@link self::requirement()}'s proof is
 *   where a host's SQLite is asked, because that is where a host is asked anything. The one
 *   exception is {@link self::withoutForeignKeys()}, which migrations run inside.
 * - **A lock is waited for, for {@link self::BUSY_MILLISECONDS}.** Two requests at once on a shared
 *   host are ordinary, and SQLite lets one write at a time. pdo_sqlite's own wait is 60 seconds —
 *   twice the `max_execution_time` the health floor allows — so a request stuck behind a lock would
 *   be killed by PHP mid-wait, with no exception and nothing in the log to say why.
 * - **The journal is a rollback journal, never WAL**, and it is set, not assumed. WAL is faster
 *   with many readers, but it keeps its index in shared memory that every process must map, and
 *   SQLite's own documentation says it does not work over a network filesystem — which is what a
 *   shared host's webspace is. It is also a property of the *file*: a database once opened in WAL
 *   by a tool stays WAL for every request after, so the mode is asked for on every open.
 *
 * **One statement at a time, by {@link Sql}**, which binds every value by type and refuses a
 * statement whose placeholders and parameters disagree. {@link self::select()} and
 * {@link self::first()} hand each row to a mapper the caller writes — a `Closure(Row): T` — so
 * what leaves here is the site's own value objects, not rows.
 */
final readonly class Database
{
    /** How long a statement waits for another request's lock before it fails, in milliseconds. */
    private const int BUSY_MILLISECONDS = 5000;

    /** The PDO driver, as a DSN starts with it and `PDO::getAvailableDrivers()` lists it. */
    private const string DRIVER = 'sqlite';

    /** The extension that provides {@link self::DRIVER}, as `extension_loaded()` spells it. */
    private const string EXTENSION = 'pdo_sqlite';

    /** SQLite's name for a database that is never written to disk. */
    private const string MEMORY = ':memory:';

    /** How every connection is opened, and what {@link self::withoutForeignKeys()} puts back. */
    private const string FOREIGN_KEYS_ON = 'PRAGMA foreign_keys = ON';

    /** For {@link self::withoutForeignKeys()} alone. */
    private const string FOREIGN_KEYS_OFF = 'PRAGMA foreign_keys = OFF';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param PDO    $pdo  A connection {@link self::connect()} opened and configured.
     * @param string $name The file it opened, or `:memory:` — for a message to name.
     */
    private function __construct(private PDO $pdo, public string $name) {}

    /**
     * Opens the database in $file, which must already be there.
     *
     * **This never makes one**, and that is the point of having it: SQLite creates a file it is
     * asked to open, so a path misspelled in a site's code would open a new, empty database — and a
     * site that runs its migrations on the way in would fill it, and serve an empty site from it
     * without an error anywhere. {@link self::openOrCreate()} is the deliberate way to make one.
     *
     * @param File $file
     * @return self
     *
     * @throws DatabaseException if there is no file there, or it cannot be opened as a database.
     */
    public static function open(File $file): self
    {
        if (!$file->exists()) {
            throw new DatabaseException(sprintf(
                'There is no database at %s. Database::open() never makes one; openOrCreate() does.',
                $file->path,
            ));
        }

        return self::connect($file->path, true);
    }

    /**
     * Opens the database in $file, making an empty one if there is none.
     *
     * **It makes the file and never its directory**, for {@link File}'s reason: a directory
     * created by a request is created on the live server, where somebody then has to find it.
     * A directory that is not there is a {@link DatabaseException} naming the path.
     *
     * @param File $file
     * @return self
     *
     * @throws DatabaseException if the file cannot be opened or made.
     */
    public static function openOrCreate(File $file): self
    {
        return self::connect($file->path, true);
    }

    /**
     * Opens a database that lives in memory, for as long as this object does.
     *
     * For tests: configured exactly as a file is — foreign keys, the typed fetch, the wait —
     * except for the journal, which in memory has no file to be a mode of.
     *
     * @return self
     *
     * @throws DatabaseException if this PHP cannot open an SQLite database at all.
     */
    public static function inMemory(): self
    {
        return self::connect(self::MEMORY, false);
    }

    /**
     * What a site declares in its `ownRequirements()` when it keeps a database.
     *
     * **Proved by using it**, the standard `PhpExtension` keeps: PDO is asked whether it can open
     * SQLite at all, and then a connection opened the way every one here is opened is asked whether
     * it enforces foreign keys — `extension_loaded()` answers about a name, and neither question is
     * about a name. Each half is asked only once the one before it has answered yes. The one throw
     * left — a connection that will not open at all — is a {@link DatabaseException}, which
     * {@link ExtensionRequirement::check()} reads as a proof that failed.
     *
     * It is not in the framework's floor, because a host without SQLite is not broken for a site
     * that keeps no database, and a floor that failed it would say so.
     *
     * @return ExtensionRequirement
     */
    #[NoDiscard('requirement() builds a declaration and registers nothing; only ownRequirements() reaches health v1')]
    public static function requirement(): ExtensionRequirement
    {
        return new ExtensionRequirement(
            self::EXTENSION,
            Level::Required,
            static fn(): bool => class_exists(PDO::class)
                && in_array(self::DRIVER, PDO::getAvailableDrivers(), true)
                && self::inMemory()->pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1,
        );
    }

    /**
     * Every row $sql reads, each handed to $map.
     *
     * The mapper runs here, once per row, and the collection it answers with holds what the mapper
     * returned — its element type read off the mapper's own return declaration, as
     * {@link Collection::map()} does, so a mapper that declares none is refused.
     *
     * @template T
     * @param Sql              $sql
     * @param Closure(Row): T  $map
     * @return Collection<T>
     * @throws DatabaseException if SQLite refuses the statement.
     */
    #[NoDiscard('select() answers with what it read and changes nothing, so a dropped result read it for nothing')]
    public function select(Sql $sql, Closure $map): Collection
    {
        /** @var Collection<Row> $rows */
        $rows = $this->attempt($sql->prepared, function () use ($sql): Collection {
            $statement = $this->run($sql);
            $rows      = [];

            while (($values = $statement->fetch()) !== false) {
                $rows[] = new Row($values);
            }

            return new Collection(Row::class)->with(...$rows);
        });

        return $rows->map($map)->settled();
    }

    /**
     * The first row $sql reads, handed to $map — or null where it reads none.
     *
     * It reads one row and closes the cursor, so write the `LIMIT` that says so in the statement:
     * SQLite still does the work of every row a query matches unless it is told otherwise. The
     * cursor is closed before $map runs, because in a rollback journal an unfinished read holds a
     * shared lock that every writer waits behind.
     *
     * @template T
     * @param Sql              $sql
     * @param Closure(Row): T  $map
     * @return T|null
     * @throws DatabaseException if SQLite refuses the statement.
     */
    #[NoDiscard('first() answers with what it read and changes nothing, so a dropped result read it for nothing')]
    public function first(Sql $sql, Closure $map): mixed
    {
        $row = $this->attempt($sql->prepared, function () use ($sql): ?Row {
            $statement = $this->run($sql);
            $values    = $statement->fetch();

            $statement->closeCursor();

            return $values === false ? null : new Row($values);
        });

        return $row === null ? null : $map($row);
    }

    /**
     * Runs a statement that writes, and answers how many rows it changed.
     *
     * @param Sql $sql
     * @return int
     * @throws DatabaseException if SQLite refuses the statement — a constraint it breaks, say.
     */
    public function execute(Sql $sql): int
    {
        return $this->attempt($sql->prepared, fn(): int => $this->run($sql)->rowCount());
    }

    /**
     * The rowid of the row the last `INSERT` on this connection wrote.
     *
     * @return int
     */
    #[NoDiscard('lastInsertId() answers a question and changes nothing, so a dropped result does nothing')]
    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Runs $work inside one transaction: committed when it returns, rolled back when it throws.
     *
     * **What $work throws arrives unchanged.** The rollback happens in a `finally` behind a flag
     * rather than in a `catch`, so nothing here names what it would be catching — the guidelines
     * refuse `catch (Throwable)` everywhere but the request's own last line — and nothing wraps,
     * re-types or swallows it. The rollback is skipped where SQLite has already rolled back on its
     * own, as it does on a full disk or an I/O error, so the original is not replaced by "no
     * transaction is active". A `COMMIT` that fails — a deferred constraint, say — throws from inside
     * the `try` and is rolled back the same way.
     *
     * **It begins `IMMEDIATE`**, taking the write lock at the start rather than at the first write.
     * Almost every transaction worth having reads and then writes — is this slug taken, then insert
     * it — and two of those begun `DEFERRED` at once each hold a read lock the other's write waits
     * for: a deadlock SQLite breaks by failing one at once, with no wait at all. Taken up front, the
     * second simply waits its turn.
     *
     * **A transaction inside another is refused**, rather than turned into a savepoint. A nested
     * call is almost always a helper that opens its own transaction being called from inside a
     * caller's, and whether the helper's commit really commits is the one question a savepoint
     * answers differently from what the helper's author meant. Refused, the author finds out the
     * first time it runs.
     *
     * @template T
     * @param Closure(self): T $work Handed this database.
     * @return T What $work returned.
     *
     * @throws SqlException if a transaction is already open on this connection.
     * @throws DatabaseException if SQLite will not begin it, or refuses the commit.
     */
    public function transaction(Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new SqlException(
                'A transaction was begun inside another on ' . $this->name . '. Nothing here nests: '
                . 'run the inner work inside the outer transaction, without a second one.',
            );
        }

        $this->exec('BEGIN IMMEDIATE');
        $committed = false;

        try {
            $result = $work($this);
            $this->exec('COMMIT');
            $committed = true;
        } finally {
            if (!$committed && $this->pdo->inTransaction()) {
                $this->exec('ROLLBACK');
            }
        }

        return $result;
    }

    /**
     * Runs $work with this connection's foreign keys off, and turns them on again however it ends.
     *
     * For the one job that needs it: rebuilding a table other tables refer to, the way SQLite
     * documents — make the new table, copy, drop the old, rename — where the drop, with foreign keys
     * on, deletes every row that referred to the old table through any `ON DELETE CASCADE`, in
     * silence. {@link Migrations} runs every migration inside this, and checks the references before
     * each one commits.
     *
     * **Refused inside a transaction**, because SQLite ignores the pragma there: asked inside one, it
     * would answer as though it had worked and change nothing.
     *
     * @template T
     * @param Closure(self): T $work Handed this database.
     * @return T What $work returned.
     *
     * @throws SqlException if a transaction is open on this connection.
     */
    public function withoutForeignKeys(Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new SqlException(
                'Foreign keys were switched off inside a transaction on ' . $this->name . ', where SQLite '
                . 'ignores the switch. Switch them off first, and begin the transaction inside.',
            );
        }

        $this->exec(self::FOREIGN_KEYS_OFF);

        try {
            return $work($this);
        } finally {
            $this->exec(self::FOREIGN_KEYS_ON);
        }
    }

    /**
     * $work, with a failure of the driver's turned into a {@link DatabaseException} naming $statement
     * and this database, the driver's own exception kept as its cause.
     *
     * The statement is named as it was prepared, placeholders and all: a value is bound and never
     * spliced into the text, so a message never quotes what a visitor sent.
     *
     * @template T
     * @param string       $statement
     * @param Closure(): T $work
     * @return T
     * @throws DatabaseException
     */
    private function attempt(string $statement, Closure $work): mixed
    {
        try {
            return $work();
        } catch (PDOException $cause) {
            throw new DatabaseException(
                sprintf("'%s' failed on %s: %s", $statement, $this->name, $cause->getMessage()),
                0,
                $cause,
            );
        }
    }

    /**
     * Runs a statement with no parameters and no rows: a pragma, or a transaction's own.
     *
     * @param string $statement
     * @return void
     * @throws DatabaseException
     */
    private function exec(string $statement): void
    {
        $this->attempt($statement, fn(): int|false => $this->pdo->exec($statement));
    }

    /**
     * Prepares $sql, binds its parameters and runs it — inside {@link self::attempt()}, always.
     *
     * @param Sql $sql
     * @return PDOStatement
     */
    private function run(Sql $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql->prepared);

        foreach ($sql->parameters as $binding) {
            $binding->bindTo($statement);
        }

        $statement->execute();

        return $statement;
    }

    /**
     * Opens and configures a connection — the one place a `PDO` is made.
     *
     * The configuration runs inside the same `try` as the opening, because SQLite opens lazily: a
     * file that is not a database opens fine and fails at the first pragma that reads it. So does a
     * file another connection is holding in WAL, which cannot be switched back while it is held —
     * refused, rather than a request quietly running in the mode this class exists to keep out.
     *
     * @param string $name   A path, or `:memory:`.
     * @param bool   $onDisk Whether there is a file whose journal mode to set.
     * @return self
     *
     * @throws DatabaseException if the connection cannot be opened, or will not be configured.
     */
    private static function connect(string $name, bool $onDisk): self
    {
        try {
            $pdo = new PDO(self::DRIVER . ':' . $name, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NAMED,
            ]);

            $pdo->exec(sprintf('PRAGMA busy_timeout = %d', self::BUSY_MILLISECONDS));
            $pdo->exec(self::FOREIGN_KEYS_ON);

            if ($onDisk) {
                $pdo->exec('PRAGMA journal_mode = DELETE');
            }
        } catch (PDOException $cause) {
            throw new DatabaseException(
                sprintf('The database at %s cannot be opened: %s', $name, $cause->getMessage()),
                0,
                $cause,
            );
        }

        return new self($pdo, $name);
    }
}
