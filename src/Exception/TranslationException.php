<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use LogicException;

/**
 * The TranslationException class. Text that cannot be put into a language.
 *
 * Three causes and one meaning — something in this repository is written wrong: a catalog case
 * with no `#[Translation]`, a message ICU cannot format, or a translatable rendered where nothing
 * says which language it is in. A `LogicException`, like every exception here that means that:
 * nothing recovers from it, and the last-resort handler in `public/index.php` logs it.
 */
class TranslationException extends LogicException implements SiteException
{
}
