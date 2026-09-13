<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use TypeError;

/**
 * The CollectionException class. Thrown when a collection is asked to hold, or to produce,
 * something it was not declared for.
 *
 * One thrower — {@link \Phpanta\Support\TypedItems}, and therefore both collections that use it.
 * Five refusals: an item that is not the declared type, a declared type that names nothing, `null`
 * or `array` as a declared type, a `join()` on a collection that does not hold strings, and a
 * `map()` callback whose return type cannot be read.
 *
 * **Extends `TypeError`, which is not a stylistic choice and is the reason this class could be
 * written at all.** Every one of those five is a type being wrong, which is what `TypeError` means
 * — and the collections have raised exactly that since they were written, with `SupportTest`
 * asserting the message text of several. Naming the throw without changing what it *is* keeps all
 * of that true: `instanceof TypeError` still holds, every existing `catch` and `expectException`
 * still matches, and what changed is only that the exception now says which layer raised it.
 *
 * The consequence worth knowing is one level up. `TypeError` extends `Error`, so this is the one
 * exception in this namespace that a `catch (Exception)` does **not** see — which is the argument
 * {@link SiteException} was written to make.
 */
class CollectionException extends TypeError implements SiteException
{
}
