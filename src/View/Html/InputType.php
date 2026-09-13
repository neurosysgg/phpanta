<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The InputType enum. What an `<input type>` may say — the kinds a {@link \Phpanta\Form\Field} is.
 *
 * A misspelled type is the silent kind of wrong: the browser renders a plain text box, so a
 * `pasword` field shows the password as it is typed and nothing anywhere says so.
 */
enum InputType: string
{
    case Text     = 'text';
    case Email    = 'email';
    case Password = 'password';
    case Number   = 'number';
    case Checkbox = 'checkbox';
    case Hidden   = 'hidden';
    case Search   = 'search';
    case Url      = 'url';
    case Tel      = 'tel';

    /** A file, which only a {@link \Phpanta\Http\FormEncoding::Multipart} form sends — see Form::render(). */
    case File     = 'file';
}
