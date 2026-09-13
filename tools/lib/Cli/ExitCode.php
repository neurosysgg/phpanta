<?php

declare(strict_types=1);

namespace Phpanta\Tool\Cli;

/**
 * The ExitCode enum. What a {@link Command} hands back to the shell.
 *
 * Three cases and no more, because a caller can only usefully distinguish three things: it worked,
 * it ran and found something wrong, or it was asked for something that is not a command. That last
 * distinction is the one worth keeping separate — a script driving these tools wants to retry a
 * `Failure` and never a `Usage`.
 */
enum ExitCode: int
{
    case Success = 0;
    case Failure = 1;
    case Usage   = 2;
}
