<?php

declare(strict_types=1);

namespace Phpanta\Form;

use NoDiscard;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Translatable;

/**
 * The Required rule. The field has to be filled in — with something other than spaces.
 *
 * Stricter than the browser's `required`, which a single space satisfies: a name of one space is
 * not a name. For a checkbox it means the box is ticked, since an unticked one sends nothing.
 */
final readonly class Required implements Rule
{
    /**
     * @param string $value
     * @return Translatable|null
     */
    #[NoDiscard('check() only asks; a call whose result goes nowhere checked nothing')]
    public function check(string $value): ?Translatable
    {
        return trim($value) === '' ? FrameworkText::FieldRequired : null;
    }
}
