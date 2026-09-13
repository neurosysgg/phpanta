<?php

declare(strict_types=1);

namespace Phpanta\Tool\Cli;

use RuntimeException;

/**
 * The UsageException class. Thrown when a command line cannot be read as one.
 *
 * Distinct from a command that runs and finds a problem: this is the argv itself being wrong, and
 * {@link Runner} answers it with the usage line and {@link ExitCode::Usage} rather than with
 * whatever the command would have reported.
 */
final class UsageException extends RuntimeException
{
}
