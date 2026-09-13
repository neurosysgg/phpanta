<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use LogicException;

/**
 * The AppException class. Thrown when the booted {@link \Phpanta\App} is asked for and there is
 * none, or is not the one asked for, or a second app is booted beside the first.
 *
 * **Extends `LogicException` for the reason {@link RouteException} does**: every one of those is an
 * entry point written wrong — a script that loads classes without booting, or two sites in one
 * process — and nothing a request did. Nothing recovers from it and nothing should try.
 */
class AppException extends LogicException implements SiteException
{
}
