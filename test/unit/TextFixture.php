<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * A catalog for the tests: one case of each shape a real one has, and one it must never have.
 */
enum TextFixture: string implements Translatable
{
    use Translated;

    #[Translation(en: 'downloads', de: 'Downloads')]
    case Plain = 'plain';

    #[Translation(
        en: '{count, plural, one {# download} other {# downloads}}',
        de: '{count, plural, one {# Download} other {# Downloads}}',
    )]
    case Counted = 'counted';

    #[Translation(en: 'only in English')]
    case EnglishOnly = 'english-only';

    #[Translation(
        en: 'Read {guide} first, then {code} & the rest.',
        de: 'Lies {code}, bevor du {guide} & den Rest liest.',
    )]
    case Placed = 'placed';

    #[Translation(en: 'a {brace} and a { stray one', de: 'eine {brace} und eine } lose')]
    case Stray = 'stray';

    #[Translation(en: '<b>bold</b> & "quoted"', de: '<b>fett</b> & „zitiert“')]
    case Markup = 'markup';

    /** No attribute, on purpose: the case a catalog must never have. */
    case Untranslated = 'untranslated';
}
