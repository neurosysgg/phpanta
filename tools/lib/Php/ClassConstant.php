<?php

declare(strict_types=1);

namespace Phpanta\Tool\Php;

/**
 * The ClassConstant class. A `Foo::class` reference — what `new Collection(Label::class)` takes.
 *
 * Constructed from the real class name, so `Label::class` at the call site is what reaches the
 * page rather than the string `'Label'`. The short name is what renders, for the reason
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
