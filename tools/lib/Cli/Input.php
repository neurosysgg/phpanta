<?php

declare(strict_types=1);

namespace Phpanta\Tool\Cli;

/**
 * The Input class. One command line, parsed against the {@link Option}s its command declares.
 *
 * Replaces the two hand-rolled parsers this repo had grown, and fixes what both of them did with a
 * flag they did not recognise, which was nothing at all. A mistyped `--clover` meant
 * `tools/merge-coverage.php` reported success and wrote no report; that is now a
 * {@link UsageException} naming the flag.
 *
 * `getopt()` is still not what this wants, for the reason `merge-coverage.php` gave when it declined
 * it: `getopt()` stops at the first non-option argument, so every flag written after a path is
 * silently dropped — and `composer coverage` passes both of its paths first.
 */
final readonly class Input
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param list<string>              $operands The positional arguments, in order.
     * @param array<string, string|true> $flags   Flag name => its value, or true when it takes none.
     */
    private function __construct(private array $operands, private array $flags) {}

    /**
     * Parses a command's arguments, rejecting anything it did not declare.
     *
     * Accepts `--flag value` and `--flag=value` alike, because both get typed.
     *
     * @param list<string> $arguments Everything after the script name.
     * @param Command      $command   Consulted for the options it accepts.
     * @return self
     *
     * @throws UsageException if a flag is unknown, or a value flag is given no value.
     */
    public static function parse(array $arguments, Command $command): self
    {
        /** @var array<string, Option> $known */
        $known = [];

        foreach ($command->options() as $option) {
            $known[$option->flag()] = $option;
        }

        $operands = [];
        $flags    = [];
        $count    = count($arguments);

        for ($i = 0; $i < $count; $i++) {
            $argument = $arguments[$i];

            if (!str_starts_with($argument, '--')) {
                $operands[] = $argument;
                continue;
            }

            $name  = substr($argument, 2);
            $value = null;

            if (str_contains($name, '=')) {
                [$name, $value] = explode('=', $name, 2);
            }

            $option = $known[$name] ?? null;

            if ($option === null) {
                throw new UsageException(sprintf("unknown option '--%s'", $name));
            }

            if (!$option->takesValue()) {
                $flags[$name] = true;
                continue;
            }

            $value ??= $arguments[++$i] ?? null;

            // `''` is refused alongside `null`, because `--clover=` and a bare `--clover` at the
            // end of the line are the same mistake typed two ways. Stored, the empty string would
            // make `has()` say the flag was given and hand an empty path to whatever reads it — for
            // `merge-coverage`, a report writer that dies with a stack trace. This class exists to
            // answer that in one sentence and a usage line.
            if ($value === null || $value === '') {
                throw new UsageException(sprintf("option '--%s' needs a value", $name));
            }

            $flags[$name] = $value;
        }

        return new self($operands, $flags);
    }

    /**
     * The positional argument at an index, or null if the command line had no such argument.
     *
     * @param int $index
     * @return string|null
     */
    public function operand(int $index): ?string
    {
        return $this->operands[$index] ?? null;
    }

    /**
     * How many positional arguments were given.
     *
     * @return int
     */
    public function operandCount(): int
    {
        return count($this->operands);
    }

    /**
     * Whether a flag was given at all.
     *
     * @param Option $option
     * @return bool
     */
    public function has(Option $option): bool
    {
        return isset($this->flags[$option->flag()]);
    }

    /**
     * A value flag's value, or null if it was not given.
     *
     * @param Option $option
     * @return string|null
     */
    public function value(Option $option): ?string
    {
        $value = $this->flags[$option->flag()] ?? null;

        return is_string($value) ? $value : null;
    }
}
