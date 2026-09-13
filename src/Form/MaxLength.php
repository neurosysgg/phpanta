<?php

declare(strict_types=1);

namespace Phpanta\Form;

use NoDiscard;
use Phpanta\Exception\FormException;
use Phpanta\Support\Charset;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Translatable;

/**
 * The MaxLength rule. The field holds at most so many characters.
 *
 * Counted in characters — Unicode code points — not in bytes, so an umlaut is one. The browser's
 * `maxlength`, which {@link Form::render()} writes from this, counts UTF-16 units instead, and an
 * emoji is two of those: the page can only ever stop a visitor short of this limit, never let them
 * past it, which is the direction a difference can safely go.
 */
final readonly class MaxLength implements Rule
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $max The most characters the field may hold.
     * @throws FormException if $max is below one — a field that may hold nothing is not a field.
     */
    public function __construct(public int $max)
    {
        if ($max < 1) {
            throw new FormException(sprintf('A field may hold at most %d characters, which is not a length.', $max));
        }
    }

    /**
     * @param string $value
     * @return Translatable|null
     */
    #[NoDiscard('check() only asks; a call whose result goes nowhere checked nothing')]
    public function check(string $value): ?Translatable
    {
        return mb_strlen($value, Charset::Utf8->canonical()) > $this->max
            ? FrameworkText::FieldTooLong->with(max: $this->max)
            : null;
    }
}
