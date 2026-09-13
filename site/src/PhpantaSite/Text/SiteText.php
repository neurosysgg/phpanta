<?php

declare(strict_types=1);

namespace PhpantaSite\Text;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * The words every page shares: the pages' names, the shell, the language switch, the not-found page.
 *
 * Each page's own prose is a catalog of its own, beside this one. German uses "du", as the
 * framework's own words do.
 */
enum SiteText: string implements Translatable
{
    use Translated;

    #[Translation(en: 'Home', de: 'Startseite')]
    case Home = 'home';

    #[Translation(en: 'Getting started', de: 'Erste Schritte')]
    case GettingStarted = 'getting-started';

    #[Translation(en: 'The rules', de: 'Die Regeln')]
    case Rules = 'rules';

    #[Translation(en: 'Architecture', de: 'Architektur')]
    case Architecture = 'architecture';

    #[Translation(en: 'Pages', de: 'Seiten')]
    case Pages = 'pages';

    #[Translation(en: 'Languages', de: 'Sprachen')]
    case Languages = 'languages';

    #[Translation(
        en: 'Phpanta — a small full-stack web framework for plain PHP 8.5 and browser-native TypeScript.',
        de: 'Phpanta — ein kleines Full-Stack-Webframework für schlichtes PHP 8.5 und browsernatives TypeScript.',
    )]
    case Description = 'description';

    #[Translation(
        en: 'Phpanta is MIT-licensed. This site is built with it and exported to static files; {source} is part of '
            . 'the repository.',
        de: 'Phpanta steht unter der MIT-Lizenz. Diese Website ist damit gebaut und als statische Dateien '
            . 'exportiert; {source} liegt im Repository.',
    )]
    case Footer = 'footer';

    #[Translation(en: 'its source', de: 'ihr Quelltext')]
    case FooterSource = 'footer-source';

    #[Translation(en: 'Not found', de: 'Nicht gefunden')]
    case NotFound = 'not-found';

    #[Translation(
        en: 'There is no page at this address. {start}.',
        de: 'Unter dieser Adresse gibt es keine Seite. {start}.',
    )]
    case NotFoundText = 'not-found-text';

    #[Translation(en: 'Start at the beginning', de: 'Fang am Anfang an')]
    case StartAtTheBeginning = 'start-at-the-beginning';
}
