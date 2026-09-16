<?php

declare(strict_types=1);

namespace Phpanta\Text;

/**
 * The DropText enum. What the page at `/drop` says: how to reveal a drop, and why one did not reveal.
 *
 * A catalog of its own rather than cases on {@link AdminText}: this is said to whoever holds a link,
 * who is nobody the admin knows.
 */
enum DropText: string implements Translatable
{
    use Translated;

    /** The page's name, as it is titled. */
    #[Translation(en: 'drop', de: 'Übergabe')]
    case Heading = 'heading';

    /** What the page is for. */
    #[Translation(
        en: 'Something was left here for you. Reveal it when you are ready: '
            . 'one meant to be read once is gone as soon as it has been.',
        de: 'Hier wurde etwas für dich hinterlegt. Deck es auf, wenn du bereit bist: '
            . 'Was nur einmal gelesen werden soll, ist danach fort.',
    )]
    case Intro = 'intro';

    /** The token's field — filled in from the link where the page's script runs. */
    #[Translation(en: 'The token from your link', de: 'Der Schlüssel aus deinem Link')]
    case Token = 'token';

    /** The password's field. */
    #[Translation(en: 'Its password, if it has one', de: 'Sein Passwort, falls es eins hat')]
    case Password = 'password';

    /** The button. */
    #[Translation(en: 'Reveal', de: 'Aufdecken')]
    case Reveal = 'reveal';

    /** Nothing opens at the link: every kind of nothing, said one way. */
    #[Translation(
        en: 'There is nothing here: it has expired, was read once already, or never was.',
        de: 'Hier ist nichts: Es ist abgelaufen, wurde schon einmal gelesen, oder es war nie da.',
    )]
    case Absent = 'absent';

    /** It needs a password, and none was given. */
    #[Translation(en: 'It needs its password.', de: 'Es braucht sein Passwort.')]
    case NeedsPassword = 'needs-password';

    /** It needs a password, and that was not it. */
    #[Translation(en: 'That is not its password.', de: 'Das ist nicht sein Passwort.')]
    case WrongPassword = 'wrong-password';

    /** Above text revealed from a drop that opens as often as it is asked. */
    #[Translation(en: 'Here it is.', de: 'Hier ist es.')]
    case Revealed = 'revealed';

    /** Above text revealed from a drop meant to be read once. */
    #[Translation(
        en: 'It was meant to be read once, and is gone from the server now: keep a copy if you need one.',
        de: 'Es sollte nur einmal gelesen werden und ist jetzt vom Server verschwunden: '
            . 'Heb dir eine Kopie auf, wenn du sie brauchst.',
    )]
    case ReadOnce = 'read-once';

    /** The deployment cannot count attempts, so it reveals nothing — failing closed. */
    #[Translation(
        en: 'Nothing can be revealed here right now.',
        de: 'Hier kann gerade nichts aufgedeckt werden.',
    )]
    case Uncounted = 'uncounted';
}
