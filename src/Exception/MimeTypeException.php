<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use LogicException;

/**
 * The MimeTypeException class. Thrown when a {@link \Phpanta\Http\MimeType} is handed a subtype
 * that is not one.
 *
 * The Http namespace's counterpart to {@link SecurityPolicyException}, which covers the value
 * objects under Http\Security: both exist so a malformed value fails loudly where it is written
 * rather than going out as a header the recipient is left to guess at.
 *
 * Named for the one class that throws it. Widen it if a second validated value lands beside
 * MimeType; do not reach for it from one that is not about a media type.
 *
 * **Extends `LogicException`, and that is the classification rather than a detail.** Nothing
 * recovers from it and nothing should try: this is not a condition a caller acts on, it is
 * "something in this repository is written wrong, go and fix it" — which is what SPL's
 * `LogicException` means. Saying it in the type rather than only the prose also settles a question
 * that would otherwise follow it around: whether every `MimeType` the site builds owes an
 * `@throws` for a failure that only happens when the site is already broken. It does not.
 *
 * The handler at the door in `public/index.php` does catch it, which is not the contradiction it
 * reads as: it recovers nothing, it turns what would have been a PHP fatal into a 500 with an empty
 * body and a line in the log. See {@link SiteException}.
 */
class MimeTypeException extends LogicException implements SiteException
{
}
