<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Attribute;
use Phpanta\Exception\GuidelineException;

/**
 * The BareCall attribute. Excuses one method from the rule that an array function a collection
 * already answers is that collection's member, and says why.
 *
 * Third of the family, and the narrowest of the three. {@link BareArray} is about a shape that is
 * *declared* and {@link BareString} about a word that is *written*; this is about a call, so it
 * hangs on the method the call is inside rather than on the call itself — a line number is not
 * something an attribute can name, and a method is the smallest thing that is.
 *
 * **The rule is not "no `array_*`".** `GuidelineTest` carries a table of
 * the array functions a {@link Collection} has a member for — `array_map` against `map()`,
 * `array_unique` against `unique()`, and four more — and only those are asked about. `array_slice`,
 * `array_shift` and `array_merge` are not on it, because no member answers them and a rule that
 * demanded an excuse for a function with no replacement would be asking for an apology rather than
 * an argument. The table grows when a member is written, which is what makes it the answer to
 * "which member should I have used" as well as the rule itself.
 *
 * The three collection files are exempt outright, the way an enum declaration is exempt from
 * {@link BareString}: `Collection`, `SearchableCollection` and `TypedItems` *are* the members, and
 * the array functions are what they are made of.
 *
 * The function is named rather than inferred, because a method may hold two and only one of them is
 * the one being argued about — the same reason {@link BareString} names its literal. The reason is
 * mandatory on the same terms as both of theirs.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class BareCall
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $function The array function this method may call, by name.
     * @param string $reason   Why the collection's member is not what this wants. Never empty.
     *
     * @throws GuidelineException if the name is empty or names no function, or the reason is
     *                             empty.
     */
    public function __construct(public string $function, public string $reason)
    {
        // Asked of PHP rather than of the table, which lives in the test: an excuse naming
        // `array_uniqe` would otherwise sit there looking answered while the real call went on
        // being unexcused, which is the one failure an excuse mechanism must not have.
        if ($function === '' || !function_exists($function)) {
            throw new GuidelineException(
                "#[BareCall] needs the name of a function that exists; '{$function}' is not one. "
                . 'An excuse for a call nobody makes excuses nothing.',
            );
        }

        if ($reason === '') {
            throw new GuidelineException(
                '#[BareCall] needs a reason: which door this is, or what the member cannot do '
                . 'here. A call nobody argued for is the habit being interrupted.',
            );
        }
    }
}
