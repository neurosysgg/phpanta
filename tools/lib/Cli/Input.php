<?php

declare(strict_types=1);

namespace Phpanta\Tool\Cli;

/**
 * The Input class. One command line, parsed against the {@link Option}s and the {@link Arity} its
 * command declares.
 *
 * Replaces the two hand-rolled parsers this repo had grown, and fixes what both of them did with a
 * flag they did not recognise, which was nothing at all. A mistyped `--clover` meant
 * `merge-coverage` reported success and wrote no report; that is now a {@link UsageException}
 * naming the flag. **Everything else a command did not ask for is refused the same way**: a short
 * option, a value on a flag that takes none, an operand too many.
 *
 * `getopt()` is still not what this wants, for the reason `merge-coverage` gave when it declined it:
 * `getopt()` stops at the first non-option argument, so every flag written after a path is silently
 * dropped — and `composer coverage` passes its paths first.
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
     * Accepts `--flag value` and `--flag=value` alike, because both get typed. `--` ends the options:
     * everything after it is an operand, however it is spelled.
     *
     * @param list<string> $arguments Everything after the script name.
     * @param Command      $command   Consulted for the options and the operands it accepts.
     * @return self
     *
     * @throws UsageException if a flag is unknown, short, given a value it does not take or not given
     *                        one it does, or the operands are not as many as the command takes.
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
        $options  = true;

        for ($i = 0; $i < $count; $i++) {
            $argument = $arguments[$i];

            if ($options && $argument === '--') {
                $options = false;
                continue;
            }

            // A lone `-` is an operand by convention (standard input), and so is anything once `--`
            // has ended the options.
            if (!$options || !str_starts_with($argument, '-') || $argument === '-') {
                $operands[] = $argument;
                continue;
            }

            // One dash and a letter is a short option, which no command here declares. Taken as an
            // operand, it is the word a command that reads none silently ignores — `push-update -n`
            // was a real push. Refused, so the mistake reads as one.
            if (!str_starts_with($argument, '--')) {
                throw new UsageException(sprintf(
                    "unknown option '%s' — options are spelled out in full, --like-this",
                    $argument,
                ));
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
                // `--dry-run=no` reads as a dry run switched off and would be one switched on. A flag
                // that takes no value refuses one rather than guessing which of the two was meant.
                if ($value !== null) {
                    throw new UsageException(sprintf("option '--%s' takes no value", $name));
                }

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

        $arity = $command->operands();

        if (!$arity->allows(count($operands))) {
            throw new UsageException($arity->refusal(count($operands)));
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
