<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * The choices a form test's `<select>` offers — a catalog too, so an option shows its words while
 * its value stays its key.
 */
enum ChoiceFixture: string implements Translatable
{
    use Translated;

    #[Translation(en: 'red', de: 'rot')]
    case Red = 'red';

    #[Translation(en: 'blue', de: 'blau')]
    case Blue = 'blue';
}
