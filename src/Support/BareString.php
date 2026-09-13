<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Attribute;
use Phpanta\Exception\GuidelineException;

/**
 * The BareString attribute. Excuses one string literal from the rule that a name is an enum case,
 * and says why.
 *
 * The rule {@link \NeuroSYS\Test\Unit\GuidelineTest} enforces is not "no literals" — a tagline, a
 * heading and an exception message are all text and none of them is a name. It is narrower and
 * catches only the two shapes where a literal is a vocabulary written out:
 *
 * - **A literal an enum in reach already spells.** `'GET'` in a file that can see
 *   {@link \Phpanta\Http\HttpMethod} is that enum's case written as text, and the two drift apart
 *   in silence.
 * - **A literal spelled twice under `src/`.** One occurrence is a value; two is a vocabulary with
 *   no name, and nothing keeps the copies in step.
 *
 * Punctuation is neither — `'/'`, `', '`, `"\n"` are structure, and naming them would be worse
 * than leaving them — so a literal with no letter or digit in it is not a name and needs no
 * excuse.
 *
 * The literal is named rather than inferred from the line, because a method holds several and only
 * one of them is the one being argued about. The reason is mandatory on the same terms as
 * {@link BareArray}'s.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class BareString
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $literal The literal as it is written, decoded — `"\n"` is one character here.
     * @param string $reason  Why this one stays a literal. Never empty.
     *
     * @throws GuidelineException if either half is empty.
     */
    public function __construct(public string $literal, public string $reason)
    {
        if ($literal === '') {
            throw new GuidelineException(
                '#[BareString] needs the literal it excuses. The empty string carries no letter, '
                . 'so it is never a name and never needs one.',
            );
        }

        if ($reason === '') {
            throw new GuidelineException(
                '#[BareString] needs a reason: what the word is, if it is not the name the '
                . 'vocabulary beside it already has. A literal nobody argued for is the habit.',
            );
        }
    }
}
