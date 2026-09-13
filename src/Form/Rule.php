<?php

declare(strict_types=1);

namespace Phpanta\Form;

use Phpanta\Text\Translatable;

/**
 * The Rule interface. One thing a field's value has to be.
 *
 * **An empty value passes every rule but {@link Required}.** Whether a field may be left empty is
 * one question with one answer, asked once; a rule that also refused `''` would make an optional
 * email field required by being an email field.
 */
interface Rule
{
    /**
     * What is wrong with $value, in words the page shows beside the field — or null, where nothing is.
     *
     * @param string $value The value as it was sent, `''` when nothing was.
     * @return Translatable|null
     */
    public function check(string $value): ?Translatable;
}
