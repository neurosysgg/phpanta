<?php

declare(strict_types=1);

namespace PhpantaSite\Text;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * The home page's words. A heading's case is keyed by its anchor, so `#made-of` is
 * `HomeText::MadeOf` in every language.
 */
enum HomeText: string implements Translatable
{
    use Translated;

    #[Translation(
        en: 'A small full-stack web framework for plain PHP 8.5 and browser-native TypeScript. No runtime '
            . 'dependencies, no template language, no composer on the server: a site vendors Phpanta as a git '
            . 'submodule, requires one autoloader, and deploys plain files.',
        de: 'Ein kleines Full-Stack-Webframework für schlichtes PHP 8.5 und browsernatives TypeScript. Keine '
            . 'Laufzeitabhängigkeiten, keine Template-Sprache, kein Composer auf dem Server: Eine Website bindet '
            . 'Phpanta als Git-Submodul ein, lädt einen einzigen Autoloader und liefert schlichte Dateien aus.',
    )]
    case Lede = 'lede';

    #[Translation(
        en: 'It was extracted from the site of {site}, which is built on it. So is this one: every page here is a '
            . 'markup tree its view builds, rendered inside its shell and exported to static files for GitHub Pages '
            . 'by the framework\'s own export command — once in each of its languages.',
        de: 'Es wurde aus der Website von {site} herausgelöst, die darauf aufbaut. Diese hier ebenfalls: Jede Seite '
            . 'ist ein Markup-Baum, den ihre View baut, in ihrer Shell gerendert und vom Export-Befehl des Frameworks '
            . 'als statische Dateien für GitHub Pages geschrieben — einmal in jeder ihrer Sprachen.',
    )]
    case Extracted = 'extracted';

    #[Translation(en: 'What it is made of', de: 'Woraus es besteht')]
    case MadeOf = 'made-of';

    #[Translation(en: 'Typed HTTP.', de: 'Typisiertes HTTP.')]
    case TypedHttpLead = 'typed-http-lead';

    #[Translation(
        en: '{lead} A request is parsed defensively into a readonly {request}, a response is a typed object, and '
            . 'every header is a name case and a value class.',
        de: '{lead} Eine Anfrage wird defensiv in einen unveränderlichen {request} geparst, eine Antwort ist ein '
            . 'typisiertes Objekt, und jeder Header ist ein Namens-Case und eine Wertklasse.',
    )]
    case TypedHttp = 'typed-http';

    #[Translation(en: 'A markup tree.', de: 'Ein Markup-Baum.')]
    case MarkupTreeLead = 'markup-tree-lead';

    #[Translation(
        en: '{lead} A view returns nodes, never a string. Escaping and the URL-scheme check live in {render}, so '
            . 'they hold however an element was built — this page included, in both of its languages.',
        de: '{lead} Eine View liefert Knoten, nie einen String. Escaping und die Prüfung des URL-Schemas stecken in '
            . '{render}, also gelten sie, wie auch immer ein Element gebaut wurde — diese Seite eingeschlossen, in '
            . 'beiden ihrer Sprachen.',
    )]
    case MarkupTree = 'markup-tree';

    #[Translation(en: 'Immutable, lazy collections', de: 'Unveränderliche, lazy ausgewertete Collections')]
    case CollectionsLead = 'collections-lead';

    #[Translation(
        en: '{lead} for every group that crosses a public boundary.',
        de: '{lead} für jede Gruppe, die eine öffentliche Grenze überquert.',
    )]
    case Collections = 'collections';

    #[Translation(en: 'Translation.', de: 'Übersetzung.')]
    case TranslationLead = 'translation-lead';

    #[Translation(
        en: '{lead} Visible text is a {translatable}, and the tree puts every word into the nearest {lang} when it '
            . 'renders — a link or a piece of code inside a sentence included, wherever each language\'s word '
            . 'order puts it.',
        de: '{lead} Sichtbarer Text ist ein {translatable}, und der Baum setzt jedes Wort beim Rendern in das '
            . 'nächstgelegene {lang} — auch einen Link oder ein Stück Code in einem Satz, dorthin, wo die '
            . 'Wortstellung der jeweiligen Sprache es haben will.',
    )]
    case Translation = 'translation';

    #[Translation(en: 'A signed API.', de: 'Eine signierte API.')]
    case SignedApiLead = 'signed-api-lead';

    #[Translation(
        en: '{lead} {address} is the one address family that writes, and every call is signed with an ECDSA key the '
            . 'server cannot use. A deployment is one signed request.',
        de: '{lead} {address} ist die einzige Adressfamilie, die schreibt, und jeder Aufruf ist mit einem '
            . 'ECDSA-Schlüssel signiert, den der Server nicht benutzen kann. Ein Deployment ist eine einzige '
            . 'signierte Anfrage.',
    )]
    case SignedApi = 'signed-api';

    #[Translation(en: 'Health checks.', de: 'Health-Checks.')]
    case HealthLead = 'health-lead';

    #[Translation(
        en: '{lead} What a site needs of its host is declared in code, and the running deployment reports whether '
            . 'it has it.',
        de: '{lead} Was eine Website von ihrem Host braucht, steht im Code, und das laufende Deployment meldet, ob '
            . 'es das hat.',
    )]
    case Health = 'health';

    #[Translation(en: 'SPA navigation', de: 'SPA-Navigation')]
    case NavigationLead = 'navigation-lead';

    #[Translation(
        en: '{lead} in which every link is a real {href}, so a page without JavaScript works the same.',
        de: '{lead}, in der jeder Link ein echtes {href} ist, sodass eine Seite ohne JavaScript genauso '
            . 'funktioniert.',
    )]
    case Navigation = 'navigation';

    #[Translation(en: 'Where to go next', de: 'Wie es weitergeht')]
    case Next = 'next';

    #[Translation(
        en: '{link} — vendoring it into a site, the app, building, deploying.',
        de: '{link} — es in eine Website einbinden, die App, bauen, deployen.',
    )]
    case NextGettingStarted = 'next-getting-started';

    #[Translation(
        en: '{link} — the habits it replaces, and why each one fails loudly.',
        de: '{link} — die Gewohnheiten, die es ersetzt, und warum jede davon laut scheitert.',
    )]
    case NextRules = 'next-rules';

    #[Translation(
        en: '{link} — a request, traced through the layers.',
        de: '{link} — eine Anfrage, verfolgt durch die Schichten.',
    )]
    case NextArchitecture = 'next-architecture';

    #[Translation(
        en: '{link} — the source, and the full documents under {docs}.',
        de: '{link} — der Quelltext und die vollständigen Dokumente unter {docs}.',
    )]
    case NextRepository = 'next-repository';

    #[Translation(en: 'The repository', de: 'Das Repository')]
    case Repository = 'repository';
}
