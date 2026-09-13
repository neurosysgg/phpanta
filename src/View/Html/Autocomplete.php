<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The Autocomplete enum. What an `autocomplete` attribute may say — the handful of the standard's
 * tokens a form here needs.
 *
 * The two password tokens are the ones that matter most: `current-password` is what lets a password
 * manager fill a login, and `new-password` is what makes it offer a generated one on a sign-up rather
 * than the password the visitor already uses everywhere. A misspelled token is ignored in silence.
 */
enum Autocomplete: string
{
    case Off             = 'off';
    case On              = 'on';
    case Name            = 'name';
    case Email           = 'email';
    case Username        = 'username';
    case CurrentPassword = 'current-password';
    case NewPassword     = 'new-password';
}
