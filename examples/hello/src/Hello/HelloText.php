<?php

declare(strict_types=1);

namespace Hello;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * Every word the example says, in both of its languages.
 */
enum HelloText: string implements Translatable
{
    use Translated;

    #[Translation(en: 'Hello, world!', de: 'Hallo, Welt!')]
    case World = 'world';

    #[Translation(en: 'Now greet {someone}.', de: 'Jetzt grüß {someone}.')]
    case GreetSomeone = 'greet-someone';

    #[Translation(en: 'Hello, {name}!', de: 'Hallo, {name}!')]
    case Someone = 'someone';

    #[Translation(
        en: 'Your name has {letters, plural, one {one letter} other {# letters}}.',
        de: 'Dein Name hat {letters, plural, one {einen Buchstaben} other {# Buchstaben}}.',
    )]
    case Letters = 'letters';

    #[Translation(en: 'Back to the world', de: 'Zurück zur Welt')]
    case Back = 'back';

    #[Translation(en: 'Nobody lives here.', de: 'Hier wohnt niemand.')]
    case Nobody = 'nobody';
}
