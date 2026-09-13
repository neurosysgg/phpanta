<?php

declare(strict_types=1);

namespace Phpanta\Tool\Cli;

/**
 * The Runner class. Turns an argv into a {@link Command} run and an exit status.
 *
 * **The decision and the `exit` are separate**, which is the arrangement `Auth::accepts()` already
 * has beside the 401 it exits with: {@link self::execute()} returns an {@link ExitCode} and can be
 * asserted against, and {@link self::run()} is only that wrapped in the call that ends the process.
 * A method that ends the request cannot be tested, so everything worth testing lives beside it.
 */
final readonly class Runner
{
    /**
     * Runs a command and ends the process with its status.
     *
     * @param Command      $command
     * @param list<string> $argv The raw `$argv`, script name included.
     * @return never
     */
    public static function run(Command $command, array $argv): never
    {
        exit(self::execute($command, array_slice($argv, 1), Output::standard())->value);
    }

    /**
     * Parses the arguments, runs the command, and answers a malformed command line with its usage.
     *
     * @param Command      $command
     * @param list<string> $arguments Everything after the script name.
     * @param Output       $output
     * @return ExitCode
     */
    public static function execute(Command $command, array $arguments, Output $output): ExitCode
    {
        try {
            $input = Input::parse($arguments, $command);
        } catch (UsageException $exception) {
            $output->error(sprintf("%s: %s\n", $command->name(), $exception->getMessage()));
            $output->error(self::usage($command));

            return ExitCode::Usage;
        }

        return $command->run($input, $output);
    }

    /**
     * The usage line, built from what the command says about itself.
     *
     * @param Command $command
     * @return string
     */
    public static function usage(Command $command): string
    {
        return sprintf("usage: php tools/%s.php %s\n", $command->name(), $command->usage());
    }
}
