<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use LogicException;

/**
 * The SecurityPolicyException class. Thrown when a response security policy, or one of the
 * value objects it is built from, is constructed with something that isn't valid on the wire.
 *
 * Counterpart to {@link MimeTypeException}: both exist so a malformed value fails loudly where it
 * is written, rather than being emitted as a header a browser silently drops.
 *
 * **Extends `LogicException`, and that is the classification rather than a detail.** Nothing
 * recovers from it and nothing should try: this is not a condition a caller acts on, it is
 * "something in this repository is written wrong, go and fix it" — which is what SPL's
 * `LogicException` means. Saying it in the type rather than only the prose also settles a question
 * that would otherwise follow it around: whether every policy the security headers compose owes
 * an `@throws` for a failure that only happens when the site is already broken. It does not.
 *
 * The handler at the door in `public/index.php` does catch it, which is not the contradiction it
 * reads as: it recovers nothing, it turns what would have been a PHP fatal into a 500 with an empty
 * body and a line in the log. See {@link SiteException}.
 */
class SecurityPolicyException extends LogicException implements SiteException
{
}
