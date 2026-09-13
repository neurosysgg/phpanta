# Data — a database, when a site keeps one

`Phpanta\Data` is a thin layer over one SQLite file: a connection opened the one way the framework
opens one, statements that bind every value by type, rows read through typed readers into the site's
own value objects, transactions, and migrations that run once. It is not an ORM. The site writes its
SQL, and the framework makes the ways SQL goes quietly wrong loud.

**It is optional.** The framework keeps no database of its own, and a site that keeps none needs
nothing from this directory, not even the extension. See [the requirement](#the-requirement).

---

## The pieces

| Class | Is |
|---|---|
| [`Database`](../src/Data/Database.php) | one connection: `open()`, `openOrCreate()`, `inMemory()`; `select()`, `first()`, `execute()`, `lastInsertId()`, `transaction()`; `requirement()` |
| [`Sql`](../src/Data/Sql.php) | one statement and its parameters, compared where it is written |
| [`Row`](../src/Data/Row.php) | one row, read through `string()`, `int()`, `float()`, `bool()`, `nullableString()`, `nullableInt()` |
| [`Table`](../src/Data/Table.php), [`Column`](../src/Data/Column.php) | the interfaces a site's table and column enums implement |
| [`Migration`](../src/Data/Migration.php), [`Migrations`](../src/Data/Migrations.php) | one schema change, and the runner that applies the pending ones |

Three exceptions go with them, all in `Phpanta\Exception`. `DatabaseException` (a `RuntimeException`)
means a database cannot be opened. `SqlException` (a `LogicException`) means a statement, a read or a
transaction is written wrong. `MigrationException` (a `LogicException`) means a list of migrations
disagrees with the history the database records. **A statement SQLite refuses is none of these.** A
syntax error or a broken constraint arrives as PHP's own `PDOException`, which keeps the SQLSTATE.

## The rules it keeps

- **A table and a column are enum cases, not strings.** A site declares one enum per table's columns,
  implementing `Column`, and its tables in one implementing `Table`. A reader takes a `Column` and
  nothing else, so a misspelled column is a parse error, not a failure on the one page that reads it.
- **A value is bound, never written into the statement.** Only code goes into the text, meaning a
  case's `->value` or a keyword. Every value is a parameter: a PHP named argument spelled like its
  placeholder, `slug: $slug` for `:slug`, of type int, string, bool, float or null.
- **A statement is checked where it is built.** A placeholder with no parameter, a parameter with no
  placeholder, or a positional parameter is an `SqlException` on the line that wrote it. The same goes
  for three shapes SQLite would accept in silence:
  - `?`, `@name` or `$name`, which SQLite binds as `NULL` when nothing fills them;
  - a second statement, which PDO drops without a word;
  - a float that is not finite.
- **A reader refuses. It never converts.** `int()` on a string, `bool()` on a `2` and `string()` on a
  `NULL` are each an `SqlException` naming the column and the type found, never the value. The one
  widening is the language's own: `float()` takes an int. `bool()` reads SQLite's truth, 1 or 0, and
  nothing else.
- **A column named twice is refused.** Rows are fetched `FETCH_NAMED`, so both `id`s of a join
  survive, and reading `id` says to alias them apart. `FETCH_ASSOC` would have kept one in silence.
- **What leaves is the site's own objects.** `select()` and `first()` take a `Closure(Row): T`, and
  `select()` answers with a `Collection<T>` typed by the mapper's return declaration, the way
  `Collection::map()` types one. The mapper has already run by the time it answers.

### How a connection is opened

Every setting is there because its default fails quietly:

| Setting | Why |
|---|---|
| `ERRMODE_EXCEPTION` | a failed statement is never a `false` nobody checked |
| no emulated prepares, no stringified fetch | a value is bound rather than spliced into the text, and comes back typed |
| `PRAGMA foreign_keys = ON` | SQLite's default is to parse a `REFERENCES` and ignore it |
| `PRAGMA busy_timeout = 5000` | two requests at once are ordinary on a shared host, and SQLite lets one write at a time. pdo_sqlite's own wait is 60 s, twice the `max_execution_time` floor, so PHP would kill a waiting request with nothing in the log |
| `PRAGMA journal_mode = DELETE` | **not WAL**; see below |

**No WAL, on purpose.** WAL is faster with many readers, but it keeps its index in shared memory that
every process must map. SQLite's documentation says it does not work over a network filesystem, which
is what a shared host's webspace usually is. WAL is also a property of the file: once a tool opens
the database in WAL, every request after that is in WAL too. So each open asks for a rollback
journal. While another connection holds the file in WAL, the mode cannot be switched, and the open is
a `DatabaseException` rather than a request quietly running in WAL.

**`open()` never makes a file.** SQLite creates any file it is asked to open. A misspelled path would
therefore be a new, empty database, which migrations would fill and the site would serve from without
an error. `openOrCreate()` is the deliberate way to make one. It makes the file but never its
directory, which is what `File` holds to as well.

### Transactions

`transaction(Closure $work)` commits when `$work` returns and rolls back when it throws. What it
threw arrives unchanged: the rollback sits in a `finally` behind a flag, so nothing catches
`Throwable`. It begins **`IMMEDIATE`**. A transaction that reads and then writes would otherwise
deadlock against another one begun at the same moment, and SQLite breaks that deadlock by failing one
side at once rather than waiting. **Nesting is refused**, not turned into savepoints. A nested call
is nearly always a helper that opens its own transaction, and whether its commit really commits is
the question a savepoint answers differently from what the helper's author meant.

## An example

A site's tables and columns:

```php
enum Tables: string implements Table
{
    case Posts = 'posts';
}

enum PostColumn: string implements Column
{
    case Id    = 'id';
    case Slug  = 'slug';
    case Title = 'title';
    case Draft = 'draft';
}
```

Its migrations, as an enum too. The value is the id each one is recorded under, and the cases run in
the order they are written:

```php
enum Schema: string implements Migration
{
    case CreatePosts = '2026-09-13-create-posts';
    case IndexSlugs  = '2026-09-20-index-slugs';

    public function id(): string
    {
        return $this->value;
    }

    public function apply(Database $database): void
    {
        $database->execute(new Sql(match ($this) {
            self::CreatePosts => sprintf(
                'CREATE TABLE %s (%s INTEGER PRIMARY KEY, %s TEXT NOT NULL UNIQUE, %s TEXT NOT NULL, %s INTEGER NOT NULL)',
                Tables::Posts->value, PostColumn::Id->value, PostColumn::Slug->value,
                PostColumn::Title->value, PostColumn::Draft->value,
            ),
            self::IndexSlugs => sprintf('CREATE INDEX posts_by_slug ON %s (%s)', Tables::Posts->value, PostColumn::Slug->value),
        }));
    }
}
```

Opened, migrated and read into a value object of its own:

```php
$database = Database::open(App::current()->dataFile(SiteData::Posts));
(void) new Migrations(...Schema::cases())->apply($database);

$post = $database->first(
    new Sql('SELECT id, slug, title FROM posts WHERE slug = :slug AND draft = :draft LIMIT 1', slug: $slug, draft: false),
    static fn(Row $row): Post => new Post(
        $row->int(PostColumn::Id),
        $row->string(PostColumn::Slug),
        $row->string(PostColumn::Title),
    ),
);
```

`SiteData::Posts` is a `DataFileName` case of the site's own. It should be **untracked**: the file
exists only on a deployment, so the health report states whether it is there rather than failing
when it is not.

## Migrations

- **The history is a prefix of the list, or nothing runs.** The database records each applied id in
  `phpanta_migrations`, in order. An applied id that has vanished, been renamed or moved is a
  `MigrationException`, which names where the list and the history part. The live schema has already
  been changed by what that migration said, so the fix goes in the list: put the migration back as it
  was, and write a new one for the change.
- **One transaction each, recorded in the same transaction.** A migration that throws is undone
  whole, SQLite's schema changes being transactional, and it is not recorded. The next run tries it
  again. A migration must therefore not begin a transaction of its own, which would be refused as
  nesting anyway.
- **One statement per `execute()`.** An `Sql` refuses a second statement, so a migration that
  creates two tables makes two calls.
- **Two requests at once apply each migration once.** The history is read again inside each
  `IMMEDIATE` transaction, so the second request waits for the lock and then finds the id recorded.
- **It is cheap when nothing is pending**: one read of `sqlite_master`, one of the history, and no
  lock. That is why the example applies them wherever it opens the database. A push that adds a
  migration therefore takes effect on the first request after it.

## The requirement

pdo_sqlite is **not** in the framework's floor ([health.md](health.md#the-frameworks-floor)). A
host without SQLite is not broken for a site that keeps no database, and a floor that failed it would
say so. A site that keeps one declares it in its app:

```php
protected function ownRequirements(): Collection
{
    return new Collection(Requirement::class)->with(Database::requirement());
}
```

The declaration is `pdo_sqlite`, required, proved by use in the way `PhpExtension` proves the
framework's five. PDO is asked whether it can open SQLite, and then a connection opened the usual way
is asked whether it enforces foreign keys, because a build of SQLite without foreign keys ignores the
pragma too. The framework's `composer.json` lists the extension under `suggest` rather than `require`
for the same reason.

## Deploying a database

**The database lives on the host, and no deploy should carry it there.**

- **A push never ships `data/`**, so it never touches the database. It ships code, and a pending
  migration then runs on the next request.
- **A full deploy that copies `data/` to the host can.** If `data/` is copied with rsync and no
  `--delete`, a database file under a working copy's `data/` is uploaded over the live one, holding
  whatever the developer had locally. A site that keeps a database must exclude the file (and its
  `-journal`) from that copy, as it excludes its credentials, and keep the live database only on the
  host. Back it up from there.
- **The first open on a fresh host is `openOrCreate()`**, run deliberately once. Otherwise the
  directory and file are made by hand and every later open is `open()`. Either way the directory has
  to be writable by PHP, since SQLite writes its journal beside the file.
