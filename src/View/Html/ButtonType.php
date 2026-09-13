<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The ButtonType enum. What a `<button type>` may say, of what the framework writes.
 *
 * One case: {@link \Phpanta\Form\Form}'s submit button says what it is rather than leaving it to the
 * default, which is `submit` only inside a form — the same button moved outside one does nothing.
 */
enum ButtonType: string
{
    case Submit = 'submit';
}
