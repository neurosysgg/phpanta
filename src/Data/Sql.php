<?php

declare(strict_types=1);

namespace Phpanta\Data;

use Phpanta\Exception\SqlException;
use Phpanta\Support\BareArray;
use Phpanta\Support\SearchableCollection;

/**
 * The Sql class. One statement, as the site's code wrote it, and the values bound into it.
 *
 * ```php
 * new Sql('SELECT title FROM posts WHERE slug = :slug AND rating >= :rating', slug: $slug, rating: 4)
 * ```
 *
 * **Each parameter is a PHP named argument spelled like its placeholder.** `slug: $slug` fills
 * `:slug`, so the pairing is written once, at the call, in a form PHP itself parses; and the types
 * a parameter may have — int, string, bool, float, null — are the variadic's declared type, so a
 * value of any other kind is a `TypeError` at the call rather than something PDO stringifies. What
 * the text may have *written into it* is code only — a {@link Table} or {@link Column} case's
 * value, a keyword — and never a value; see {@link Table}.
 *
 * **The statement and its parameters are compared where it is built**, the way `CspHost` checks an
 * origin at its constructor: a placeholder with no parameter, or a parameter with no placeholder,
 * is an {@link SqlException} naming both, on the line that wrote it — rather than PDO's "number of
 * bound variables does not match" at the first request that runs it, or no error at all. Three
 * more shapes are refused for being silent in SQLite:
 *
 * - **A positional parameter.** Unnamed, there is nothing to compare it with.
 * - **Any placeholder but `:name`** — `?`, `?1`, `@name`, `$name`. SQLite binds all of them, and
 *   binds one nobody filled as `NULL`: a query that matches nothing, or a row written with a hole,
 *   and no error anywhere.
 * - **A second statement.** PDO prepares the first statement of a string and drops the rest
 *   without a word, so a migration written as two `CREATE TABLE`s would create one. One statement
 *   per `Sql`; a trailing `;` is fine.
 *
 * Placeholders are found by a scan that steps over string literals, quoted identifiers and
 * comments, so `':not_a_placeholder'` in a literal is text, as SQLite reads it.
 */
final readonly class Sql
{
    /**
     * What the scan stops at, in order: a string literal, the three ways SQLite quotes an
     * identifier, the two comments — all stepped over whole — then a named placeholder (1), any
     * other placeholder (2), and a semicolon (3).
     *
     * The look-behinds keep an identifier that merely contains `$` or `:` from reading as a
     * placeholder, which SQLite allows and nobody writes; a false refusal there is loud, which is
     * the right direction for the error to fall.
     */
    private const string TOKENS = '/'
        . '\'(?:[^\']|\'\')*\''
        . '|"(?:[^"]|"")*"'
        . '|`(?:[^`]|``)*`'
        . '|\[[^\]]*\]'
        . '|--[^\n]*'
        . '|\/\*.*?(?:\*\/|\z)'
        . "|(?<![A-Za-z0-9_\$:]):([A-Za-z0-9_]+)"
        . "|(?<![A-Za-z0-9_\$])([?@\$][A-Za-z0-9_]*)"
        . '|(;)'
        . '/s';

    /** What may follow the one statement's `;`: whitespace and comments, and nothing else. */
    private const string AFTER_THE_END = '/\A(?:\s+|--[^\n]*|\/\*.*?\*\/)*\z/s';

    /**
     * The parameters, keyed by the placeholder each fills.
     *
     * @var SearchableCollection<Binding>
     */
    public SearchableCollection $parameters;

    /**
     * The text SQLite is handed: the statement as written, with each placeholder a float fills
     * wrapped in `CAST(… AS REAL)` — see {@link Binding}. {@link self::$text} stays as written, for
     * every message that quotes it.
     */
    public string $prepared;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string                     $text          One statement, with its values as `:name`
     *                                                  placeholders.
     * @param int|string|bool|float|null ...$parameters Each named like the placeholder it fills.
     *
     * @throws SqlException if the text is blank, holds more than one statement or a placeholder
     *                      other than `:name`, or if the placeholders and parameters disagree.
     */
    public function __construct(public string $text, int|string|bool|float|null ...$parameters)
    {
        if (trim($text) === '') {
            throw new SqlException('A statement needs some SQL in it.');
        }

        $bindings = new SearchableCollection(Binding::class);

        foreach ($parameters as $name => $value) {
            if (is_int($name)) {
                throw new SqlException(sprintf(
                    'Parameter %d of `%s` has no name. Name each after its placeholder — `slug: $slug` '
                    . 'for `:slug` — so the two can be compared.',
                    $name + 1,
                    $text,
                ));
            }

            if (is_float($value) && !is_finite($value)) {
                throw new SqlException(sprintf(
                    'Parameter `%s` of `%s` is not a finite number, and SQLite has no way to store one.',
                    $name,
                    $text,
                ));
            }

            $bindings = $bindings->with($name, new Binding($name, $value));
        }

        $placeholders = [];
        $foreign      = null;
        $end          = null;

        $prepared = preg_replace_callback(
            self::TOKENS,
            #[BareArray('preg_replace_callback() hands each match over as an array, offsets and all')]
            static function (array $match) use (&$placeholders, &$foreign, &$end, $bindings): string {
                [$token, $offset] = $match[0];

                if ($match[1][0] !== null) {
                    $placeholders[$match[1][0]] = true;

                    return is_float($bindings->find($match[1][0])?->value) ? 'CAST(' . $token . ' AS REAL)' : $token;
                }

                if ($match[2][0] !== null) {
                    $foreign ??= $token;
                } elseif ($match[3][0] !== null) {
                    $end ??= $offset;
                }

                return $token;
            },
            $text,
            -1,
            $count,
            PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        );

        if ($prepared === null) {
            throw new SqlException(sprintf(
                '`%s` could not be scanned for placeholders: %s.',
                $text,
                preg_last_error_msg(),
            ));
        }

        if ($foreign !== null) {
            throw new SqlException(sprintf(
                '`%s` writes the placeholder `%s`. Only `:name` is bound here: SQLite binds an unfilled '
                . '`?`, `@name` or `$name` as NULL, without an error.',
                $text,
                $foreign,
            ));
        }

        if ($end !== null && preg_match(self::AFTER_THE_END, substr($text, $end + 1)) !== 1) {
            throw new SqlException(sprintf(
                '`%s` is more than one statement. PDO would run the first and drop the rest without a '
                . 'word; write one Sql for each.',
                $text,
            ));
        }

        $disagreements = [];

        foreach ($placeholders as $name => $_) {
            if ($bindings->find((string) $name) === null) {
                $disagreements[] = 'no parameter fills :' . $name;
            }
        }

        foreach ($bindings as $name => $_) {
            if (!isset($placeholders[$name])) {
                $disagreements[] = 'no placeholder reads ' . $name . ':';
            }
        }

        if ($disagreements !== []) {
            throw new SqlException(sprintf(
                '`%s` and its parameters disagree: %s. A parameter nothing reads is usually a '
                . 'placeholder somebody misspelled.',
                $text,
                implode('; ', $disagreements),
            ));
        }

        $this->parameters = $bindings;
        $this->prepared   = $prepared;
    }
}
