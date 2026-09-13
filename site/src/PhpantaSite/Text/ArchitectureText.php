<?php

declare(strict_types=1);

namespace PhpantaSite\Text;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * The architecture page's words. A heading's case is keyed by its anchor.
 *
 * The two trees on the page are code, and stay in English in both languages — their notes are
 * identifiers and paths as much as they are prose.
 */
enum ArchitectureText: string implements Translatable
{
    use Translated;

    #[Translation(
        en: 'A request, from the rewrite that sends it to {index} to the bytes that go back.',
        de: 'Eine Anfrage, vom Rewrite, der sie an {index} schickt, bis zu den Bytes, die zurückgehen.',
    )]
    case Lede = 'lede';

    #[Translation(en: 'The request, traced', de: 'Die Anfrage, verfolgt')]
    case TheRequest = 'the-request';

    #[Translation(
        en: 'The exception handler is installed first because it has to work when nothing else did: it logs, sends a '
            . 'bare 500 if the headers have not gone out, and tells the visitor nothing else.',
        de: 'Der Exception-Handler wird zuerst installiert, weil er funktionieren muss, wenn nichts anderes '
            . 'funktioniert hat: Er protokolliert, sendet ein schlichtes 500, wenn die Header noch nicht raus sind, '
            . 'und sagt dem Besucher sonst nichts.',
    )]
    case Handler = 'handler';

    #[Translation(en: 'The layers', de: 'Die Schichten')]
    case Layers = 'layers';

    #[Translation(
        en: 'The dependencies point one way. Controllers use services and views, views and models build elements out '
            . 'of {html}, and {html} depends on nothing above it.',
        de: 'Die Abhängigkeiten zeigen in eine Richtung. Controller benutzen Services und Views, Views und '
            . 'Models bauen Elemente aus {html}, und {html} hängt von nichts darüber ab.',
    )]
    case LayersText = 'layers-text';

    #[Translation(en: 'The app', de: 'Die App')]
    case TheApp = 'the-app';

    #[Translation(
        en: 'One per process, booted by the site\'s autoloader and read back with {current}. Constructing one does '
            . 'nothing, booting it twice is booting it once, and a second app is refused. The framework\'s deep code '
            . '— the site gate, the API\'s replay serial, the health report — asks the booted app, rather than every '
            . 'constructor on the way down carrying the site\'s facts.',
        de: 'Eine pro Prozess, vom Autoloader der Website gebootet und mit {current} wieder gelesen. Eine zu '
            . 'konstruieren tut nichts, sie zweimal zu booten heißt, sie einmal zu booten, und eine zweite App wird '
            . 'abgelehnt. Der tiefe Code des Frameworks — das Site-Gate, die Replay-Seriennummer der API, der '
            . 'Health-Report — fragt die gebootete App, statt dass jeder Konstruktor auf dem Weg nach unten die '
            . 'Fakten der Website mitträgt.',
    )]
    case TheAppText = 'the-app-text';

    #[Translation(en: 'The wire', de: 'Die Leitung')]
    case TheWire = 'the-wire';

    #[Translation(
        en: 'A request is parsed defensively. An unknown method is {null}, not a guess at {get}, and a target PHP\'s '
            . 'parser cannot read is kept as the path it is rather than answered with the home page. The security '
            . 'headers are typed objects, sent before anything that could fail, and every page varies on exactly '
            . 'the headers it was rendered from.',
        de: 'Eine Anfrage wird defensiv geparst. Eine unbekannte Methode ist {null}, nicht geraten als {get}, und ein '
            . 'Ziel, das der Parser von PHP nicht lesen kann, bleibt der Pfad, der es ist, statt mit der Startseite '
            . 'beantwortet zu werden. Die Security-Header sind typisierte Objekte, gesendet vor allem, was scheitern '
            . 'könnte, und jede Seite variiert genau nach den Headern, aus denen sie gerendert wurde.',
    )]
    case TheWireText = 'the-wire-text';

    #[Translation(en: 'The signed API', de: 'Die signierte API')]
    case SignedApi = 'signed-api';

    #[Translation(
        en: 'An update is a gzipped tar in the body of one {post}, signed with an ECDSA P-256 key of which the server '
            . 'holds only the public half. The signature covers the action, a timestamp, a serial the server spends '
            . 'before it applies anything, and a hash of the body. A call that is unsigned or signed wrongly is '
            . 'answered exactly as an address that does not exist.',
        de: 'Ein Update ist ein gzip-komprimiertes Tar im Body eines einzigen {post}, signiert mit einem '
            . 'ECDSA-P-256-Schlüssel, von dem der Server nur die öffentliche Hälfte hat. Die Signatur deckt die '
            . 'Aktion ab, einen Zeitstempel, eine Seriennummer, die der Server verbraucht, bevor er irgendetwas '
            . 'anwendet, und einen Hash des Bodys. Ein Aufruf, der nicht oder falsch signiert ist, wird genau so '
            . 'beantwortet wie eine Adresse, die es nicht gibt.',
    )]
    case SignedApiText = 'signed-api-text';

    #[Translation(en: 'SPA navigation', de: 'SPA-Navigation')]
    case SpaNavigation = 'spa-navigation';

    #[Translation(
        en: 'Every link is a real {href}. {navigation} intercepts a click on one that stays on this origin, asks for '
            . 'the page with {requestedWith}, and swaps the answer into {content}. A server running the framework '
            . 'answers with a fragment; a static host like this one answers with the whole page, and only its '
            . 'content and its title are taken — and, when the page is in another language, the parts of the shell '
            . 'written in the language it replaces.',
        de: 'Jeder Link ist ein echtes {href}. {navigation} fängt einen Klick auf einen Link ab, der auf diesem Origin '
            . 'bleibt, fragt die Seite mit {requestedWith} an und tauscht die Antwort in {content} ein. Ein Server '
            . 'mit dem Framework antwortet mit einem Fragment; ein statischer Host wie dieser antwortet mit der ganzen '
            . 'Seite, und nur ihr Inhalt und ihr Titel werden genommen — und, wenn die Seite in einer anderen Sprache '
            . 'ist, die Teile der Shell, die in der Sprache geschrieben sind, die sie ablöst.',
    )]
    case SpaNavigationText = 'spa-navigation-text';

    #[Translation(en: 'Further reading', de: 'Weiterlesen')]
    case FurtherReading = 'further-reading';

    #[Translation(
        en: '{doc} — the request, the app, the layers, exceptions, the markup tree',
        de: '{doc} — die Anfrage, die App, die Schichten, Exceptions, der Markup-Baum',
    )]
    case ReadArchitecture = 'read-architecture';

    #[Translation(
        en: '{doc} — the headers, the method gate, the guards, the API',
        de: '{doc} — die Header, das Methoden-Gate, die Wächter, die API',
    )]
    case ReadSecurity = 'read-security';

    #[Translation(
        en: '{doc} — the build, the element model, SPA navigation',
        de: '{doc} — der Build, das Element-Modell, die SPA-Navigation',
    )]
    case ReadFrontend = 'read-frontend';

    #[Translation(
        en: '{doc} — collections, and what stays an array',
        de: '{doc} — Collections, und was ein Array bleibt',
    )]
    case ReadCollections = 'read-collections';
}
