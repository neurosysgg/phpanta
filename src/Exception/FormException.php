<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use LogicException;

/**
 * The FormException class. Thrown when a form is declared with something it cannot be, or asked
 * about a field it does not have — a form of a class that is no field enum, a field named like the
 * form token, a rule with a length below one, a submission asked for another form's field.
 *
 * **A LogicException, because every one of these is a line in this repository written wrong.**
 * What a visitor sends cannot raise it: a value that breaks a rule is an error the form shows, and a
 * body that cannot be read is an {@link InputException}, which the router answers with a 400.
 */
class FormException extends LogicException implements SiteException
{
}
