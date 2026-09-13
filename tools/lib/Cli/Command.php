<?php

declare(strict_types=1);

namespace Phpanta\Tool\Cli;

/**
 * The Command interface. One of this repo's development commands.
 *
 * `tools/` holds four of these — `stage-release`, `release-track`, `extract-midi` and
 * `merge-coverage` — and two things that are not: `dev-router.php` is handed to `php -S` and
 * `coverage-prepend.php` is an `auto_prepend_file`. PHP loads both; nothing invokes them, so they
 * have no argv and no exit code and there is nothing here for them to implement.
 */
interface Command
{
    /**
     * The command's name, matching its entry point — `stage-release` for `tools/stage-release.php`.
     *
     * @return string
     */
    public function name(): string;

    /**
     * The argument shape, for the usage line — `<folder> [--check]`.
     *
     * @return string
     */
    public function usage(): string;

    /**
     * One line saying what the command is for.
     *
     * @return string
     */
    public function description(): string;

    /**
     * Every option this command accepts.
     *
     * Not decoration: it is what lets {@link Input} refuse a flag the command never declared,
     * instead of dropping it the way both of this repo's previous parsers did.
     *
     * @return list<Option>
     */
    public function options(): array;

    /**
     * How many operands this command takes.
     *
     * Not decoration either, for the reason {@link self::options()} is not: {@link Input} refuses a
     * count outside it, so a stray word is a usage error rather than something a command that reads
     * no operands quietly ignores.
     *
     * @return Arity
     */
    public function operands(): Arity;

    /**
     * Runs the command.
     *
     * @param Input  $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode;
}
