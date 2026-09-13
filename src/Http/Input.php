<?php

declare(strict_types=1);

namespace Phpanta\Http;

use BackedEnum;
use NoDiscard;
use Phpanta\Exception\InputException;
use Phpanta\Support\BareString;
use Phpanta\Support\Collection;
use Phpanta\Support\SearchableCollection;

/**
 * The Input class. What a query string or a form sent, asked for by {@link Parameter} and by type.
 *
 * ```php
 * $term = $request->query()->text(SearchParameter::Term);   // ?string
 * $page = $request->query()->int(SearchParameter::Page);    // ?int, or a 400
 * ```
 *
 * **Absent is null; wrong is loud.** A parameter that was not sent answers null — or false, for a
 * flag — and the page picks its default. A parameter that was sent and cannot be read as asked is
 * an {@link InputException}, which the router answers with a 400: a page never has to tell "no
 * page given" from "page=banana", and never quietly shows the first page for the second.
 *
 * **Read by this class, never by `parse_str()`.** PHP's parser turns `a.b` into `a_b`, builds arrays
 * out of `a[]`, and keeps the last of two values in silence; here a name is exactly the bytes that
 * were sent, `a[]` is a name like any other, and a name sent twice is refused when it is read,
 * because which of the two was meant is not something to guess. `+` is a space and `%xx` is a
 * byte, as the form encoding says, and anything that does not decode to UTF-8 is refused outright.
 */
#[BareString(
    'string',
    'the declared type of the two collections this holds: names and values, which are strings. The '
    . 'same scalar-in-a-class-string coincidence Route, Vocabulary and TypedItems excuse.',
)]
final readonly class Input
{
    /**
     * @param SearchableCollection<string> $values   Every name sent, with the last value sent for it.
     * @param Collection<string>           $repeated The names sent more than once.
     */
    private function __construct(
        private SearchableCollection $values,
        private Collection           $repeated,
    ) {}

    /**
     * Nothing sent.
     *
     * @return self
     */
    public static function none(): self
    {
        return new self(new SearchableCollection('string'), new Collection('string'));
    }

    /**
     * What $encoded sends, read as `application/x-www-form-urlencoded`: `&`-separated `name=value`
     * pairs, `+` for a space, `%xx` for a byte.
     *
     * A pair without an `=` is a name with an empty value, and an empty pair is nothing.
     *
     * @param string $encoded
     * @return self
     * @throws InputException if a name or a value does not decode to UTF-8.
     */
    public static function fromUrlEncoded(string $encoded): self
    {
        $input = self::none();

        foreach (explode('&', $encoded) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name           = self::decoded($name);
            $value          = self::decoded($value);

            $repeated = $input->values->find($name) !== null
                ? $input->repeated->with($name)
                : $input->repeated;

            $input = new self($input->values->with($name, $value), $repeated);
        }

        return $input;
    }

    /**
     * Whether $parameter was sent at all.
     *
     * @param Parameter $parameter
     * @return bool
     */
    #[NoDiscard('has() only asks; a call whose result goes nowhere asked nothing')]
    public function has(Parameter $parameter): bool
    {
        return $this->values->find(self::name($parameter)) !== null;
    }

    /**
     * $parameter's value as it was sent, decoded; null if it was not sent.
     *
     * @param Parameter $parameter
     * @return string|null
     * @throws InputException if it was sent more than once.
     */
    #[NoDiscard('text() only reads; a call whose result goes nowhere read nothing')]
    public function text(Parameter $parameter): ?string
    {
        return $this->one($parameter);
    }

    /**
     * $parameter's value as a whole number; null if it was not sent.
     *
     * Digits with an optional minus and no leading zero, eighteen at most — the same grammar a
     * `{name:int}` placeholder matches, and bounded for the same reason: nineteen digits can exceed
     * `PHP_INT_MAX`, and `(int)` would clamp to it in silence.
     *
     * @param Parameter $parameter
     * @return int|null
     * @throws InputException if it was sent and is not one, or sent more than once.
     */
    #[NoDiscard('int() only reads; a call whose result goes nowhere read nothing')]
    public function int(Parameter $parameter): ?int
    {
        $value = $this->one($parameter);

        if ($value === null) {
            return null;
        }

        if (preg_match('#\A-?(?:0|[1-9][0-9]{0,17})\z#', $value) !== 1) {
            throw new InputException(sprintf("'%s' is not a whole number.", self::name($parameter)));
        }

        return (int) $value;
    }

    /**
     * $parameter as a yes or a no — as a checkbox or a hand-written link sends one: `1`, `on`,
     * `true` or `yes` is true; `0`, `off`, `false`, `no`, an empty value, or not being sent at all, is
     * false. Case does not matter.
     *
     * @param Parameter $parameter
     * @return bool
     * @throws InputException if it was sent as anything else, or more than once.
     */
    #[NoDiscard('flag() only reads; a call whose result goes nowhere read nothing')]
    public function flag(Parameter $parameter): bool
    {
        // PHP's own reading of a boolean word, which is exactly the two lists above — and null for
        // anything else, which is what makes a flag sent as `maybe` loud rather than false.
        return filter_var($this->one($parameter) ?? '', FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            ?? throw new InputException(sprintf("'%s' is neither a yes nor a no.", self::name($parameter)));
    }

    /**
     * $parameter as one of $enum's cases, by its backing value; null if it was not sent.
     *
     * @template T of BackedEnum
     * @param Parameter       $parameter
     * @param class-string<T> $enum      The cases it may be.
     * @return T|null
     * @throws InputException if it was sent and names none of them, or was sent more than once.
     */
    #[NoDiscard('choice() only reads; a call whose result goes nowhere read nothing')]
    public function choice(Parameter $parameter, string $enum): ?BackedEnum
    {
        $value = $this->one($parameter);

        if ($value === null) {
            return null;
        }

        return $enum::tryFrom($value) ?? throw new InputException(
            sprintf("'%s' is not one of the choices it offers.", self::name($parameter)),
        );
    }

    /**
     * This input, if every name it holds is a case of $parameters — the page saying which
     * parameters it reads, and refusing a request that sent another.
     *
     * For an address where an unknown parameter is a mistake worth a 400 — a form whose fields
     * are all known — rather than the default, where it is ignored.
     *
     * @param class-string<Parameter&BackedEnum> $parameters
     * @return self
     * @throws InputException naming the first name that is not one of them.
     */
    public function only(string $parameters): self
    {
        foreach ($this->values->toKeys() as $name) {
            if ($parameters::tryFrom((string) $name) === null) {
                throw new InputException(sprintf("'%s' is not a parameter this address reads.", $name));
            }
        }

        return $this;
    }

    /**
     * $parameter's one value, or null.
     *
     * @param Parameter $parameter
     * @return string|null
     * @throws InputException if it was sent more than once.
     */
    private function one(Parameter $parameter): ?string
    {
        $name = self::name($parameter);

        if ($this->repeated->first(static fn(string $repeated): bool => $repeated === $name) !== null) {
            throw new InputException(sprintf("'%s' was sent more than once.", $name));
        }

        return $this->values->find($name);
    }

    /**
     * @param Parameter $parameter
     * @return string
     */
    private static function name(Parameter $parameter): string
    {
        return (string) $parameter->value;
    }

    /**
     * One side of a pair, decoded: `+` is a space, `%xx` a byte.
     *
     * @param string $encoded
     * @return string
     * @throws InputException if the bytes are not UTF-8.
     */
    private static function decoded(string $encoded): string
    {
        $decoded = rawurldecode(str_replace('+', ' ', $encoded));

        if (!mb_check_encoding($decoded, 'UTF-8')) {
            throw new InputException('Something sent does not decode to UTF-8.');
        }

        return $decoded;
    }
}
