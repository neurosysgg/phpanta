<?php

declare(strict_types=1);

namespace Phpanta\Data;

use NoDiscard;
use Phpanta\Exception\SqlException;
use Phpanta\Support\BareArray;

/**
 * The Row class. One row a statement read, and a typed reader for each kind of value in it.
 *
 * **A reader refuses; it never converts.** pdo_sqlite hands each value over as the type SQLite
 * stored it as — `INTEGER` an int, `REAL` a float, `TEXT` a string, `NULL` null — so an `int()`
 * that finds a string has found a schema and a select that disagree, and a cast would hide which
 * one is wrong behind a `0` that looks like data. The one widening allowed is the language's own:
 * {@link self::float()} takes an int, as a `float` parameter does under `strict_types`, because
 * SQLite stores a whole number written into a column without a type as an integer.
 *
 * **A column two tables both name is refused rather than won by whichever came last.**
 * {@link Database} fetches with `PDO::FETCH_NAMED`, which keeps both `id`s of a join side by side
 * where `FETCH_ASSOC` would silently keep one; reading it is an {@link SqlException} that says to
 * alias them apart.
 *
 * A refusal names the column and the type it found, **never the value**: a row can hold a
 * password's hash, and an exception's message is written to the log.
 */
final readonly class Row
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param array<string, int|string|float|null|list<int|string|float|null>> $values
     */
    #[BareArray('the door the property below is kept behind: PDOStatement::fetch() hands a row over as an array')]
    public function __construct(
        #[BareArray(
            'PDOStatement::fetch() is the door: one row as FETCH_NAMED hands it over, a name to a '
            . 'scalar, or to a list of them where a join named a column twice. The values are of '
            . 'several types, which no Collection holds; what crosses out of here is one typed value '
            . 'at a time, through a reader.',
        )]
        private array $values,
    ) {}

    /**
     * The column's text.
     *
     * @param Column $column
     * @return string
     *
     * @throws SqlException if the row has no such column, or the value is not text.
     */
    #[NoDiscard('string() reads a column and changes nothing, so a call whose result goes nowhere does nothing')]
    public function string(Column $column): string
    {
        $value = $this->value($column);

        if (!is_string($value)) {
            throw new SqlException($this->mismatch($column, 'a string', $value));
        }

        return $value;
    }

    /**
     * The column's text, or null where it is `NULL`.
     *
     * @param Column $column
     * @return string|null
     *
     * @throws SqlException if the row has no such column, or the value is neither text nor `NULL`.
     */
    #[NoDiscard('nullableString() reads a column and changes nothing, so a dropped result does nothing')]
    public function nullableString(Column $column): ?string
    {
        $value = $this->value($column);

        if ($value !== null && !is_string($value)) {
            throw new SqlException($this->mismatch($column, 'a string or null', $value));
        }

        return $value;
    }

    /**
     * The column's integer.
     *
     * @param Column $column
     * @return int
     *
     * @throws SqlException if the row has no such column, or the value is not an integer.
     */
    #[NoDiscard('int() reads a column and changes nothing, so a call whose result goes nowhere does nothing')]
    public function int(Column $column): int
    {
        $value = $this->value($column);

        if (!is_int($value)) {
            throw new SqlException($this->mismatch($column, 'an int', $value));
        }

        return $value;
    }

    /**
     * The column's integer, or null where it is `NULL`.
     *
     * @param Column $column
     * @return int|null
     *
     * @throws SqlException if the row has no such column, or the value is neither an integer nor `NULL`.
     */
    #[NoDiscard('nullableInt() reads a column and changes nothing, so a call whose result goes nowhere does nothing')]
    public function nullableInt(Column $column): ?int
    {
        $value = $this->value($column);

        if ($value !== null && !is_int($value)) {
            throw new SqlException($this->mismatch($column, 'an int or null', $value));
        }

        return $value;
    }

    /**
     * The column's number — a real, or an integer widened to one.
     *
     * @param Column $column
     * @return float
     *
     * @throws SqlException if the row has no such column, or the value is not a number.
     */
    #[NoDiscard('float() reads a column and changes nothing, so a call whose result goes nowhere does nothing')]
    public function float(Column $column): float
    {
        $value = $this->value($column);

        if (!is_float($value) && !is_int($value)) {
            throw new SqlException($this->mismatch($column, 'a float', $value));
        }

        return (float) $value;
    }

    /**
     * The column's truth, stored as SQLite stores one: the integer 1 or 0, and nothing else.
     *
     * A `2`, a `'1'` or a `NULL` is refused rather than read by PHP's truthiness, which would call
     * every one of them something — and a flag that reads `'false'` as true is the one a schema
     * change leaves behind.
     *
     * @param Column $column
     * @return bool
     *
     * @throws SqlException if the row has no such column, or the value is not 1 or 0.
     */
    #[NoDiscard('bool() reads a column and changes nothing, so a call whose result goes nowhere does nothing')]
    public function bool(Column $column): bool
    {
        $value = $this->value($column);

        return match ($value) {
            1       => true,
            0       => false,
            default => throw new SqlException($this->mismatch($column, 'a bool (1 or 0)', $value)),
        };
    }

    /**
     * The column's value, whatever it is.
     *
     * @param Column $column
     * @return mixed
     *
     * @throws SqlException if the row has no such column, or has it twice.
     */
    private function value(Column $column): mixed
    {
        $name = (string) $column->value;

        if (!array_key_exists($name, $this->values)) {
            $names = [];

            foreach ($this->values as $present => $_) {
                $names[] = '`' . $present . '`';
            }

            throw new SqlException(sprintf(
                'The row has no column `%s`; it has %s. Select it, or alias it to that name.',
                $name,
                $names === [] ? 'none' : implode(', ', $names),
            ));
        }

        $value = $this->values[$name];

        if (is_array($value)) {
            throw new SqlException(sprintf(
                'The row has %d columns named `%s`, one from each side of a join. Alias them apart: '
                . 'which one a reader meant is not something a row can guess.',
                count($value),
                $name,
            ));
        }

        return $value;
    }

    /**
     * The sentence a reader refuses with.
     *
     * @param Column $column
     * @param string $expected
     * @param mixed  $found
     * @return string
     */
    private function mismatch(Column $column, string $expected, mixed $found): string
    {
        return sprintf(
            'Column `%s` holds %s where %s was read. A reader never converts: a schema and a select '
            . 'that disagree are the fault, and a cast would hide which.',
            $column->value,
            get_debug_type($found),
            $expected,
        );
    }
}
