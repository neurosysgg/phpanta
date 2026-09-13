<?php

declare(strict_types=1);

namespace Phpanta\Tool\Php;

use UnitEnum;

/**
 * The Value class. A leaf — a string, an int, null, or an enum case.
 *
 * Scalars go through `var_export()`, which is the whole point: quoting and escaping a string for
 * PHP source is a solved problem with a function in the language.
 *
 * **An enum renders as its short name**, `Genre::Dubstep`, not the `Genre::Dubstep`
 * that `var_export()` would give. `data/releases.php` imports its enums at the top and every entry
 * beside this one is written that way, so a fully-qualified case would be correct and out of place.
 * The short name is taken off the case itself rather than typed, so it cannot disagree with the
 * class — and {@link self::className()} hands the full name back for the import list.
 */
final readonly class Value implements Expression
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string|int|float|bool|UnitEnum|null $value
     */
    public function __construct(private string|int|float|bool|UnitEnum|null $value) {}

    /**
     * @param string $indent
     * @return string
     */
    public function render(string $indent = ''): string
    {
        if ($this->value === null) {
            // var_export() writes NULL in capitals, alone among the literals it emits — and every
            // other null in `data/releases.php` is lower case, including the ones this replaces.
            return 'null';
        }

        if (!$this->value instanceof UnitEnum) {
            return var_export($this->value, true);
        }

        return self::shortName($this->value::class) . '::' . $this->value->name;
    }

    /**
     * The class this value names, for the caller's import list, or null for a scalar.
     *
     * @return string|null
     */
    public function className(): ?string
    {
        return $this->value instanceof UnitEnum ? $this->value::class : null;
    }

    /**
     * The last segment of a namespaced class name.
     *
     * @param string $class
     * @return string
     */
    public static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
