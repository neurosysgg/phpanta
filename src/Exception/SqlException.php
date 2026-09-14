<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use LogicException;

/**
 * The SqlException class. Thrown when a statement, a read of a row, or a transaction is written
 * wrong: a placeholder with no parameter, a parameter with no placeholder, two statements in one,
 * a column read as a type it does not hold, a transaction opened inside another.
 *
 * **Extends `LogicException`**, for {@link RouteException}'s reason: every one of these is the
 * site's code disagreeing with itself or with its own schema, and nothing recovers from that at
 * run time. Most fire where the mistake is written — an {@link \Phpanta\Data\Sql} checks its
 * placeholders in its constructor — so no caller owes an `@throws` for it.
 *
 * **A statement SQLite refuses is not this.** A syntax error, a constraint violated, a table that
 * is not there: those are SQLite's answers and arrive as a {@link DatabaseException} naming the
 * statement, with PHP's own `PDOException` as its cause — whose SQLSTATE is what tells a duplicate
 * from anything else.
 */
class SqlException extends LogicException implements SiteException
{
}
