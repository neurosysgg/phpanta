<?php

declare(strict_types=1);

namespace Phpanta\Text;

/**
 * The Verbatim class. Text that is the same in every language: a title, a name, a number.
 *
 * A release is called `ill` on the German page too, and `neuro.SYS` is nobody's word to translate.
 * Such a string still has to be able to stand where a {@link Translatable} stands — beside one in a
 * {@link Joined} title, say — and this is it doing so without pretending to have been translated.
 * A {@link Translation} would say the same thing less honestly, and would refuse the empty string.
 */
final readonly class Verbatim implements Translatable
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $text
     */
    public function __construct(public string $text) {}

    /**
     * @param Language $language Unread.
     * @return string
     */
    public function in(Language $language): string
    {
        return $this->text;
    }
}
