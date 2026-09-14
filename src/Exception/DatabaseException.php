<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use RuntimeException;

/**
 * The DatabaseException class. Thrown when a database cannot be opened, or will not be configured
 * the way {@link \Phpanta\Data\Database} opens every one: no file where one was expected, a file
 * that is not a database, a directory that is not there, a PHP without the SQLite driver, or an
 * SQLite that will not enforce foreign keys — and when SQLite refuses a statement: a constraint it
 * breaks, a table that is not there, a lock waited on too long. That one names the statement.
 *
 * **Extends `RuntimeException`**, unlike its two siblings under `Data/`: this is the host or the
 * deployment not holding up its end — a file missing from `data/`, a build of SQLite without
 * foreign keys — rather than code written wrong, and the same code opens the same database fine
 * somewhere else.
 *
 * **It names the file, and it carries PDO's own message and the `PDOException` as its cause.**
 * PDO's message says *what* failed ("unable to open database file", "could not find driver") and
 * never *where*, which is the half a reader of the log needs first. Neither reaches a visitor: the
 * fault page shows a message only in development and only to loopback, so a path in it is a path
 * shown to the person who deployed it.
 */
class DatabaseException extends RuntimeException implements SiteException
{
}
