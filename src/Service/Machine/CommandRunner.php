<?php

declare(strict_types=1);

namespace Phpanta\Service\Machine;

use Phpanta\Model\Machine\CommandOutcome;
use Phpanta\Model\Machine\MachinePath;
use Phpanta\Support\Diagnostics;

/**
 * The CommandRunner class. Runs one command line with the machine's shell, in a directory, for a
 * bounded time, and keeps a bounded amount of what it printed.
 *
 * **The shell is the point.** `machine v1 run` is a command line a person typed, pipes and globs and
 * all, so it is handed to `/bin/sh -c` — or `cmd /c` on Windows — whole, as one argument. Nothing is
 * interpolated into it and nothing is escaped, because nothing but the person's own line is in it.
 * Only a verified caller reaches it, with a fresh tap of a passkey or a signature for every run, and
 * only where `data/machine.json` switches commands on. It runs as the web server's user, with that
 * user's rights and no more, and reads nothing: its standard input is closed at once.
 *
 * **Bounded twice.** It is stopped after {@link self::$seconds} — the answer has to come back within
 * one request — and at most {@link self::MOST} bytes of each stream are kept; a command that prints
 * more is read to its end and the rest let go, so it never blocks on a full pipe.
 */
final readonly class CommandRunner
{
    /** The most of each stream kept, in bytes. */
    public const int MOST = 1_048_576;

    /** How long one wait for output lasts, in microseconds, before the clock is asked again. */
    private const int TICK = 100_000;

    /** How much is read from a stream at once. */
    private const int CHUNK = 65_536;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $seconds How long a command may run.
     */
    public function __construct(private int $seconds = 30) {}

    /**
     * Runs $command in $directory.
     *
     * @param string $command
     * @param string $directory
     * @return CommandOutcome
     */
    public function run(string $command, string $directory): CommandOutcome
    {
        $argv    = MachinePath::onWindows() ? ['cmd.exe', '/c', $command] : ['/bin/sh', '-c', $command];
        $streams = [0 => StreamMode::Read->pipe(), 1 => StreamMode::Write->pipe(), 2 => StreamMode::Write->pipe()];
        $pipes   = [];
        $started = hrtime(true);
        // `function` and `use (&$pipes)`, not an arrow function: proc_open() fills the pipes in by
        // reference, and `fn()` captures by value — the pipes would open and never be seen here.
        $process = Diagnostics::muted(static function () use ($argv, $streams, &$pipes, $directory): mixed {
            return proc_open($argv, $streams, $pipes, $directory);
        });

        if (!is_resource($process)) {
            return new CommandOutcome(-1, '', '', 0, started: false);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output  = '';
        $errors  = '';
        $cut     = false;
        $stopped = false;
        $exit    = -1;

        while (true) {
            $status = proc_get_status($process);
            $ready  = [$pipes[1], $pipes[2]];
            $none   = null;
            $never  = null;

            if (stream_select($ready, $none, $never, 0, self::TICK) > 0) {
                foreach ($ready as $pipe) {
                    $chunk = (string) fread($pipe, self::CHUNK);

                    if ($pipe === $pipes[1]) {
                        $output .= $chunk;
                    } else {
                        $errors .= $chunk;
                    }
                }
            }

            $cut    = $cut || strlen($output) > self::MOST || strlen($errors) > self::MOST;
            $output = substr($output, 0, self::MOST);
            $errors = substr($errors, 0, self::MOST);

            if (!$status['running']) {
                $exit    = $status['exitcode'];
                $output .= (string) stream_get_contents($pipes[1], self::MOST);
                $errors .= (string) stream_get_contents($pipes[2], self::MOST);
                break;
            }

            if (hrtime(true) - $started > $this->seconds * 1_000_000_000) {
                proc_terminate($process, 9);
                $stopped = true;
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return new CommandOutcome(
            $exit,
            substr($output, 0, self::MOST),
            substr($errors, 0, self::MOST),
            intdiv(hrtime(true) - $started, 1_000_000),
            $stopped,
            $cut || strlen($output) > self::MOST || strlen($errors) > self::MOST,
        );
    }
}
