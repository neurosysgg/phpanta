<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use RuntimeException;

/**
 * The JsonEncodingException class. Thrown when the value a {@link \Phpanta\Http\JsonResponse} was
 * handed cannot be written as JSON — a NAN or an infinity, a string that is not UTF-8, a structure
 * nested past the depth limit.
 *
 * **Extends `RuntimeException`, because PHP's own `\JsonException` is not an SPL class.** It
 * extends `Exception` directly, so there is no SPL class this one could keep a promise to, and
 * choosing a parent is choosing a classification. It is a runtime one: whether a value encodes
 * depends on the value in hand, not on the line that built it. The same class encodes on every
 * request until a division comes to NAN, or a string read off disk turns out to be Latin-1.
 *
 * **The cause is always kept.** The wrapped `\JsonException` is what says which of those it was;
 * the wrapping only adds which layer was asked and which value's class it could not write.
 */
class JsonEncodingException extends RuntimeException implements SiteException
{
}
