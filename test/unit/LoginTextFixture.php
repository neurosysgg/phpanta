<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * The login recipe's words — see docs/login.md. The two messages are cases, so each is shown in the
 * visitor's language on whichever page reads it, as a session's messages have to be.
 */
enum LoginTextFixture: string implements Translatable
{
    use Translated;

    #[Translation(en: 'Sign in', de: 'Anmelden')]
    case SignIn = 'sign-in';

    #[Translation(en: 'Sign out', de: 'Abmelden')]
    case SignOut = 'sign-out';

    #[Translation(en: 'Your account', de: 'Dein Konto')]
    case Account = 'account';

    #[Translation(en: 'Name', de: 'Name')]
    case Name = 'name';

    #[Translation(en: 'Password', de: 'Passwort')]
    case Password = 'password';

    #[Translation(en: 'Signed in as {user}.', de: 'Angemeldet als {user}.')]
    case SignedInAs = 'signed-in-as';

    #[Translation(en: 'You are signed in.', de: 'Du bist angemeldet.')]
    case SignedIn = 'signed-in';

    #[Translation(en: 'You are signed out.', de: 'Du bist abgemeldet.')]
    case SignedOut = 'signed-out';
}
