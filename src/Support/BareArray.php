<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Attribute;
use Phpanta\Exception\GuidelineException;

/**
 * The BareArray attribute. Excuses one declaration — a method, a property, a typed constant or a
 * closure — from the rule that a group of things is a {@link Collection}, and says why.
 *
 * A bare `array` is the shape every collection here was written to replace: it announces nothing
 * about what it holds, so `array<string, float>` is a docblock's promise where
 * `SearchableCollection('float')` is the language's. `GuidelineTest`
 * refuses any `array` in a declared type under `src/` that does not carry one of these, and lists
 * the ones that do — so an exception is a sentence somebody wrote rather than a habit that spread.
 *
 * The reason is mandatory and is checked here rather than only in the test, for the same reason
 * a value object checks its own shape at its constructor: the test
 * reports the fault against a list, and the constructor reports it against the line that is wrong.
 * A bare `#[BareArray]` would say the array is deliberate, which the reader already suspected;
 * what is worth saying is *which door this is* — an `explode()` on one side, a variadic on the
 * other, a tuple that is not a group at all.
 *
 * Note what it does **not** excuse: a `Collection` parameter never replaces a variadic, so a
 * method taking `string ...$parts` needs no attribute and never did. This is for the `array` that
 * is written out.
 *
 * @see BareString for the same arrangement one type down.
 */
#[Attribute(
    Attribute::TARGET_METHOD
    | Attribute::TARGET_PROPERTY
    | Attribute::TARGET_CLASS_CONSTANT
    | Attribute::TARGET_FUNCTION,
)]
final readonly class BareArray
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $reason Why this one stays an array. Never empty.
     *
     * @throws GuidelineException if the reason is empty.
     */
    public function __construct(public string $reason)
    {
        if ($reason === '') {
            throw new GuidelineException(
                '#[BareArray] needs a reason: which door this is, or which variadic, or why the '
                . 'group is not one. An array nobody argued for is the habit being interrupted.',
            );
        }
    }
}
