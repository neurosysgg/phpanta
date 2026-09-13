<?php

declare(strict_types=1);

namespace Phpanta\Tool\Cli;

/**
 * The Output class. The two streams a command writes to, and the only `fwrite` in this layer.
 *
 * Which stream carries what is the command's decision rather than this class's: a command that
 * generates a data-file entry puts its report on stderr and the entry on stdout, so `> entry.php`
 * keeps one and `2>&1 >/dev/null` keeps the other, while `merge-coverage` puts its whole report on
 * stdout because the report *is* what it was run for.
 *
 * The streams are constructor arguments so a test can hand it memory and read back what a command
 * wrote — which is the same reason `SecurityHeaders::headers()` is public next to `send()`.
 */
final readonly class Output
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param resource $out
     * @param resource $error
     */
    public function __construct(private mixed $out, private mixed $error) {}

    /**
     * The process's own stdout and stderr.
     *
     * A factory rather than a default argument because `STDOUT` and `STDERR` exist only under the
     * CLI SAPI, and a default is evaluated wherever the class is loaded.
     *
     * @return self
     */
    public static function standard(): self
    {
        return new self(STDOUT, STDERR);
    }

    /**
     * Writes to stdout.
     *
     * @param string $text
     * @return void
     */
    public function out(string $text): void
    {
        fwrite($this->out, $text);
    }

    /**
     * Writes to stderr.
     *
     * @param string $text
     * @return void
     */
    public function error(string $text): void
    {
        fwrite($this->error, $text);
    }
}
