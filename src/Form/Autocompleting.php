<?php

declare(strict_types=1);

namespace Phpanta\Form;

use Phpanta\View\Html\Autocomplete;

/**
 * The Autocompleting interface. A {@link Field} enum that also says what a browser may fill its
 * fields in with.
 *
 * Its own interface rather than a fourth method on `Field`, because most fields have nothing to
 * say — and an answer every field had to give would be an `Autocomplete::On` written everywhere to
 * mean nothing. A login form's fields are the ones that should: see {@link Autocomplete}.
 */
interface Autocompleting extends Field
{
    /**
     * What this field's value is, to a password manager and to the browser's own memory; null for
     * nothing to say.
     *
     * @return Autocomplete|null
     */
    public function autocomplete(): ?Autocomplete;
}
