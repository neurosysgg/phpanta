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
        exit(self::execute($command, array_slice($argv, 1), Output::standard(), $argv[0] ?? null)->value);
    }

    /**
     * Parses the arguments, runs the command, and answers a malformed command line with its usage.
     *
     * @param Command      $command
     * @param list<string> $arguments Everything after the script name.
     * @param Output       $output
     * @param string|null  $script    The script as it was run, for the usage line — see usage().
     * @return ExitCode
     */
    public static function execute(
        Command $command,
        array $arguments,
        Output $output,
        ?string $script = null,
    ): ExitCode {
        try {
            $input = Input::parse($arguments, $command);
        } catch (UsageException $exception) {
            $output->error(sprintf("%s: %s\n", $command->name(), $exception->getMessage()));
            $output->error(self::usage($command, $script));

            return ExitCode::Usage;
        }

        return $command->run($input, $output);
    }

    /**
     * The usage line, built from what the command says about itself.
     *
     * It names the script as it was run when that is known, which is the one spelling certain to be
     * right: a site's commands run as `php tools/<name>.php`, and the framework's export, which has
     * no copy in the site, as `php phpanta/tools/export.php`. Without it, the site's spelling.
     *
     * @param Command     $command
     * @param string|null $script
     * @return string
     */
    public static function usage(Command $command, ?string $script = null): string
    {
        return sprintf(
            "usage: php %s %s\n",
            $script ?? sprintf('tools/%s.php', $command->name()),
            $command->usage(),
        );
    }
}
