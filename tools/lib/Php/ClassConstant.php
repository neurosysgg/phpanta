<?php

declare(strict_types=1);

namespace Phpanta\Tool\Php;

/**
 * The ClassConstant class. A `Foo::class` reference — what `new Collection(Format::class)` takes.
 *
 * Constructed from the real class name, so `Format::class` at the call site is what reaches the
 * page rather than the string `'Format'`. The short name is what renders, for the reason
 * {@link Value} gives.
 */
final readonly class ClassConstant implements Expression
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param class-string $class
     */
    public function __construct(private string $class) {}

    /**
     * @param string $indent
     * @return string
     */
    public function render(string $indent = ''): string
    {
        return Value::shortName($this->class) . '::class';
    }

    /**
     * @return string
     */
    public function className(): string
    {
        return $this->class;
    }
}
