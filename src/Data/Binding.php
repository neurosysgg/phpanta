<?php

declare(strict_types=1);

namespace Phpanta\Data;

use PDO;
use PDOStatement;

/**
 * The Binding class. One parameter of an {@link Sql}: the placeholder it fills, and the value that
 * fills it, bound by its PHP type and never written into the statement.
 *
 * **SQLite has five storage classes and PHP hands it five types; two of them do not meet.**
 *
 * - **A bool is bound as the integer 1 or 0.** SQLite has no boolean; `TRUE` is spelled `1` in its
 *   own SQL, and {@link Row::bool()} reads the same two integers back and refuses anything else.
 * - **A float is bound as text, and {@link Sql} casts the placeholder to `REAL`.** PDO has no
 *   parameter type for a double, and pdo_sqlite's two options both lose it: as an integer it is
 *   truncated, and as a string it arrives as text, which a column without a declared type keeps as
 *   text — so a float went in and a string came out. The text is written with `%.17h`, seventeen
 *   significant digits and no locale, which is enough for every double to read back as exactly
 *   itself; PHP's own `(string)` uses `precision` (14) and would store `0.1 + 0.2` as `0.3`.
 */
final readonly class Binding
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string                     $name  The placeholder's name, without its colon.
     * @param int|string|bool|float|null $value
     */
    public function __construct(public string $name, public int|string|bool|float|null $value) {}

    /**
     * Binds this value to its placeholder in $statement, by type.
     *
     * @param PDOStatement $statement
     * @return void
     */
    public function bindTo(PDOStatement $statement): void
    {
        $statement->bindValue(
            ':' . $this->name,
            match (true) {
                is_bool($this->value)  => (int) $this->value,
                is_float($this->value) => sprintf('%.17h', $this->value),
                default                => $this->value,
            },
            match (true) {
                $this->value === null                       => PDO::PARAM_NULL,
                is_int($this->value), is_bool($this->value) => PDO::PARAM_INT,
                default                                     => PDO::PARAM_STR,
            },
        );
    }
}
