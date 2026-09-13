<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use LogicException;

/**
 * The InvalidValueException class. Thrown when a value object is handed something that is not the
 * kind of value it holds — a digest that is not a bcrypt hash, a count that cannot be one.
 *
 * **Refused where it is written, which is what makes it a LogicException**: the value came out of
 * this repository — a data file, a constant — and the fault is the line that wrote it, not a
 * request. The framework throws it for its own value objects, and a site's own refusals extend it,
 * so a caller can catch either the framework's kind or the site's by name.
 */
class InvalidValueException extends LogicException implements SiteException
{
}
