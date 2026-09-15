<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

/**
 * The CommandOutcome class. What a command did: how it ended, what it printed, and how long it took.
 */
final readonly class CommandOutcome
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int    $exit         Its exit status; -1 where it was stopped, or never started.
     * @param string $output       What it printed to its standard output, at most a mebibyte.
     * @param string $errors       What it printed to its standard error, at most a mebibyte.
     * @param int    $milliseconds How long it ran.
     * @param bool   $stopped      Whether it ran out of time and was stopped.
     * @param bool   $cut          Whether it printed more than was kept.
     * @param bool   $started      Whether it started at all.
     */
    public function __construct(
        public int    $exit,
        public string $output,
        public string $errors,
        public int    $milliseconds,
        public bool   $stopped = false,
        public bool   $cut = false,
        public bool   $started = true,
    ) {}
}
