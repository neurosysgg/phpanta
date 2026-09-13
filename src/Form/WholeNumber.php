<?php

declare(strict_types=1);

namespace Phpanta\Form;

use NoDiscard;
use Phpanta\Http\Input;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Translatable;

/**
 * The WholeNumber rule. The field is a whole number, in the grammar {@link Input::int()} reads one
 * in — so a value this lets through is one `int()` reads, and never one it answers with a 400.
 *
 * `1e3`, `1.0`, `+1` and `007` are refused, each for being something a person might mean as a
 * number and a program would read differently from them.
 */
final readonly class WholeNumber implements Rule
{
    /**
     * @param string $value
     * @return Translatable|null
     */
    #[NoDiscard('check() only asks; a call whose result goes nowhere checked nothing')]
    public function check(string $value): ?Translatable
    {
        if ($value === '') {
            return null;
        }

        return preg_match(Input::WHOLE_NUMBER, $value) === 1 ? null : FrameworkText::FieldNotWholeNumber;
    }
}
