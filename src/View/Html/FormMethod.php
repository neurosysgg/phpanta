<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The FormMethod enum. What a `<form method>` may say.
 *
 * Lower-case, as HTML writes them, and not {@link \Phpanta\Http\HttpMethod}: the attribute has its
 * own vocabulary — two words and `dialog` — and a form cannot send a `PUT`, however the enum on the
 * wire spells it. A misspelled method is not an error either; the browser falls back to `get` and
 * the form's fields, a password among them, go into the address bar.
 */
enum FormMethod: string
{
    case Get  = 'get';
    case Post = 'post';
}
