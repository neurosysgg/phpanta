<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use UnexpectedValueException;

/**
 * The InputException class. Thrown when what a request sent cannot be read as what the page asked
 * for — a word where a number goes, a name sent twice, a form of a kind nothing here reads.
 *
 * **Extends `UnexpectedValueException`, the opposite classification from {@link RouteException}.**
 * That one is a line in this repository written wrong; this is a value from outside that is not
 * what was expected, which is the condition a request *can* put the site in. So it is not a 500:
 * {@link \Phpanta\Router} answers it with a 400, and the page that asked never has to check.
 *
 * The message names the parameter and what was wrong with it, never the value — it is for the
 * developer reading a test, and a value a visitor sent is theirs to see, not ours to echo.
 */
class InputException extends UnexpectedValueException implements SiteException
{
}
