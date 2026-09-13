<?php

declare(strict_types=1);

namespace Phpanta\Tool\Php;

use InvalidArgumentException;
use Phpanta\Support\Collection;

/**
 * The Call class. `new Release(…)`, `Section::named(…)`, or `…->with(…)`.
 *
 * One class for all three because they differ only in what comes before the parentheses, and
 * everything interesting happens inside them: the named arguments, the trailing comments, and the
 * alignment that makes a generated entry look like the hand-written ones around it.
 *
 * **Stacked or inline is a decision, not a measurement.** A `new Format(ReleaseFormat::FLAC)` on its
 * own line reads as one thing; the same rule applied by width would put `new Release(` on one line
 * the day a title got shorter. The caller says which, because the caller knows what the line is for.
 */
final readonly class Call implements Expression
{
    /** One level of nesting, matching `data/releases.php`. */
    public const string STEP = '    ';

    /** Between an aligned value and the comment after it. */
    private const string GAP = '  ';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Expression|null $target    The value being called on, for a `->method()` call.
     * @param string          $name      What precedes the parentheses.
     * @param Collection<Argument> $arguments
     * @param bool            $stacked   One argument per line.
     * @param string|null     $class     The class named, for the import list.
     */
    private function __construct(
        private ?Expression $target,
        private string $name,
        private Collection $arguments,
        private bool $stacked,
        private ?string $class = null,
    ) {}

    /**
     * `new Foo(…)`.
     *
     * @param class-string   $class
     * @param Collection<Argument> $arguments
     * @param bool           $stacked
     * @return self
     */
    public static function create(
        string $class,
        Collection $arguments = new Collection(Argument::class),
        bool $stacked = false,
    ): self {
        return new self(null, 'new ' . Value::shortName($class), $arguments, $stacked, $class);
    }

    /**
     * `Foo::bar(…)`.
     *
     * @param class-string   $class
     * @param string         $method
     * @param Collection<Argument> $arguments
     * @param bool           $stacked
     * @return self
     */
    public static function onClass(
        string $class,
        string $method,
        Collection $arguments = new Collection(Argument::class),
        bool $stacked = false,
    ): self {
        return new self(null, Value::shortName($class) . '::' . $method, $arguments, $stacked, $class);
    }

    /**
     * `…->bar(…)`, on whatever the target renders as.
     *
     * @param Expression     $target
     * @param string         $method
     * @param Collection<Argument> $arguments
     * @param bool           $stacked
     * @return self
     */
    public static function onValue(
        Expression $target,
        string $method,
        Collection $arguments = new Collection(Argument::class),
        bool $stacked = false,
    ): self {
        return new self($target, $method, $arguments, $stacked);
    }

    /**
     * @param string $indent
     * @return string
     * @throws InvalidArgumentException if an inline call carries a comment — see
     *                                  {@link self::inlineArguments()}.
     */
    public function render(string $indent = ''): string
    {
        $head = $this->target !== null
            ? $this->target->render($indent) . '->' . $this->name
            : $this->name;

        if (!$this->stacked) {
            return $head . '(' . implode(', ', $this->inlineArguments($indent)) . ')';
        }

        $inner = $indent . self::STEP;
        $lines = [];

        foreach ($this->arguments as $argument) {
            $lines[] = $inner . $this->line($argument, $inner);
        }

        return $head . "(\n" . implode("\n", $lines) . "\n" . $indent . ')';
    }

    /**
     * Every class this call and its arguments name, for the caller's `use` list.
     *
     * @return list<string>
     */
    public function classNames(): array
    {
        $names = $this->class !== null ? [$this->class] : [];

        if ($this->target instanceof self) {
            $names = [...$names, ...$this->target->classNames()];
        }

        foreach ($this->arguments as $argument) {
            $names = [...$names, ...self::namesIn($argument->value)];
        }

        return array_values(array_unique($names));
    }

    /**
     * @param Expression|null $value
     * @return list<string>
     */
    private static function namesIn(?Expression $value): array
    {
        return match (true) {
            $value instanceof self          => $value->classNames(),
            $value instanceof ClassConstant => [$value->className()],
            $value instanceof Value         => array_filter([$value->className()]),
            default                         => [],
        };
    }

    /**
     * The arguments of a call written on one line.
     *
     * **A comment cannot be one of them, and that is refused rather than approximated.** Both
     * shapes {@link Argument} offers for the half-state are *lines*: `Argument::comment()` is a
     * `// …` between arguments, and {@link Argument::pending()} is an argument written out and
     * commented so a person can uncomment it. An inline call has no lines to put either on.
     *
     * Rendering them anyway would produce nothing anybody meant: a bare comment comes out as
     * `new Format('a', , 'b')`, which is a syntax error, and a pending argument as
     * `new Format('a', b: 'b')` — which parses, so a line meant to be uncommented later would ship
     * as live code in `data/releases.php`. The second is the one worth throwing over.
     *
     * @param string $indent
     * @return list<string>
     * @throws InvalidArgumentException if an argument is a comment or is commented out.
     */
    private function inlineArguments(string $indent): array
    {
        $rendered = [];

        foreach ($this->arguments as $argument) {
            if ($argument->value === null || $argument->commentedOut) {
                throw new InvalidArgumentException(sprintf(
                    '%s() is written on one line, so its %s cannot be commented: a comment is a '
                    . 'line and this call has none. Build it with stacked: true.',
                    $this->name,
                    $argument->name !== null ? "'" . $argument->name . "' argument" : 'argument',
                ));
            }

            $rendered[] = ($argument->name !== null ? $argument->name . ': ' : '')
                . $argument->value->render($indent);
        }

        return $rendered;
    }

    /**
     * One stacked argument, aligned against its siblings.
     *
     * @param Argument $argument
     * @param string   $indent
     * @return string
     */
    private function line(Argument $argument, string $indent): string
    {
        if ($argument->value === null) {
            return '// ' . $argument->comment;
        }

        // A commented-out argument is rendered against column zero and then prefixed line by line,
        // so its own nesting sits *after* the slashes — `//     new Plugin(…)` rather than a block
        // indented into the middle of a comment.
        $value = $argument->value->render($argument->commentedOut ? '' : $indent) . ',';
        $name  = $argument->name !== null
            // Only the single-line arguments are padded into a column: a name whose value is a
            // block sits directly against it, the way `formats:` does in the file this feeds.
            ? str_pad($argument->name . ':', $this->nameWidth($argument)) . ' '
            : '';

        $line = $name . $value;

        if ($argument->comment !== null) {
            $line = str_pad($line, $this->commentColumn()) . self::GAP . '// ' . $argument->comment;
        }

        if (!$argument->commentedOut) {
            return $line;
        }

        return implode("\n" . $indent, array_map(
            static fn(string $part): string => '// ' . $part,
            explode("\n", $line),
        ));
    }

    /**
     * The column the values line up at, or the argument's own width where it is not in the column.
     *
     * @param Argument $argument
     * @return int
     */
    private function nameWidth(Argument $argument): int
    {
        // A commented-out argument is not in the column either: it is a line to uncomment later,
        // and padding it to line up with arguments that are actually there reads as a mistake.
        if ($this->isBlock($argument) || $argument->commentedOut) {
            return strlen((string) $argument->name) + 1;
        }

        $widths = [0];

        foreach ($this->arguments as $sibling) {
            if ($sibling->name !== null && !$this->isBlock($sibling) && !$sibling->commentedOut) {
                $widths[] = strlen($sibling->name) + 1;
            }
        }

        return max($widths);
    }

    /**
     * The column trailing comments start at.
     *
     * @return int
     */
    private function commentColumn(): int
    {
        $widths = [0];

        foreach ($this->arguments as $argument) {
            if ($argument->comment === null || $argument->value === null || $this->isBlock($argument)) {
                continue;
            }

            $name = $argument->name !== null ? str_pad($argument->name . ':', $this->nameWidth($argument)) . ' ' : '';
            $widths[] = strlen($name . $argument->value->render() . ',');
        }

        return max($widths);
    }

    /**
     * Whether an argument's value spans more than one line.
     *
     * @param Argument $argument
     * @return bool
     */
    private function isBlock(Argument $argument): bool
    {
        return $argument->value !== null && str_contains($argument->value->render(), "\n");
    }
}
