<?php

declare(strict_types=1);

namespace PhpantaSite\Text;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * The getting-started page's words. A heading's case is keyed by its anchor.
 */
enum GettingStartedText: string implements Translatable
{
    use Translated;

    #[Translation(
        en: 'A site built on Phpanta is laid out by convention, vendors the framework as a git submodule, and tells '
            . 'it about itself through one class.',
        de: 'Eine Website auf Phpanta folgt einem festen Aufbau, bindet das Framework als Git-Submodul ein und sagt '
            . 'ihm über eine einzige Klasse, was es über sie wissen muss.',
    )]
    case Lede = 'lede';

    #[Translation(en: 'The layout', de: 'Der Aufbau')]
    case Layout = 'layout';

    #[Translation(en: 'Vendoring it', de: 'Einbinden')]
    case Vendoring = 'vendoring';

    #[Translation(
        en: 'The URL is relative, so every remote the site is pushed to finds its own copy of the framework beside '
            . 'it. Editing the framework in place is the point: a change is a commit in {framework}, then {add} and '
            . 'a commit in the site.',
        de: 'Die URL ist relativ, also findet jedes Remote, auf das die Website gepusht wird, seine eigene Kopie des '
            . 'Frameworks direkt daneben. Das Framework an Ort und Stelle zu bearbeiten ist gewollt: Eine Änderung '
            . 'ist ein Commit in {framework}, dann {add} und ein Commit in der Website.',
    )]
    case VendoringText = 'vendoring-text';

    #[Translation(en: 'The app', de: 'Die App')]
    case TheApp = 'the-app';

    #[Translation(
        en: 'A site is a subclass of {app}, booted once per process by its autoloader. It owes the framework a '
            . 'handful of answers:',
        de: 'Eine Website ist eine Unterklasse von {app}, einmal pro Prozess von ihrem Autoloader gebootet. Sie '
            . 'schuldet dem Framework eine Handvoll Antworten:',
    )]
    case TheAppText = 'the-app-text';

    #[Translation(
        en: 'Everything else — where {data} is, which directory is the webroot, where the update serial lives, the '
            . 'error log, the route to the API — the framework derives from those, and the derivations are final.',
        de: 'Alles andere — wo {data} liegt, welches Verzeichnis der Webroot ist, wo die Update-Seriennummer liegt, '
            . 'das Fehlerlog, die Route zur API — leitet das Framework daraus ab, und diese Ableitungen sind final.',
    )]
    case Derived = 'derived';

    #[Translation(en: 'A page', de: 'Eine Seite')]
    case APage = 'a-page';

    #[Translation(
        en: 'A route pairs a {path} case with a controller, and the controller returns a response. A {viewResponse} '
            . 'renders its view inside the app\'s shell — or, for a request {navigation} made, as a fragment led by '
            . 'its title.',
        de: 'Eine Route verbindet einen {path}-Case mit einem Controller, und der Controller liefert eine Antwort. '
            . 'Eine {viewResponse} rendert ihre View in der Shell der App — oder, für eine Anfrage von {navigation}, '
            . 'als Fragment, angeführt von ihrem Titel.',
    )]
    case APageText = 'a-page-text';

    #[Translation(en: 'Building', de: 'Bauen')]
    case Building = 'building';

    #[Translation(
        en: 'The build tools find the project by walking up from where they are run to the nearest {composer}, and '
            . 'read the site\'s namespace from its {psr4}.',
        de: 'Die Build-Werkzeuge finden das Projekt, indem sie von dort, wo sie gestartet werden, zum nächsten '
            . '{composer} hinaufsteigen, und lesen den Namespace der Website aus dessen {psr4}.',
    )]
    case BuildingText = 'building-text';

    #[Translation(en: 'Deploying', de: 'Deployen')]
    case Deploying = 'deploying';

    #[Translation(
        en: 'A PHP host gets plain files: {frameworkSource} and {frameworkAutoload}, the site\'s {source} and '
            . '{autoload}, and the prod webroot — copied by any means, or sent as one signed request by the '
            . 'framework\'s {push} command. A static host gets an export:',
        de: 'Ein PHP-Host bekommt schlichte Dateien: {frameworkSource} und {frameworkAutoload}, {source} und '
            . '{autoload} der Website und den Prod-Webroot — auf beliebigem Weg kopiert, oder als eine einzige '
            . 'signierte Anfrage durch den {push}-Befehl des Frameworks geschickt. Ein statischer Host bekommt einen '
            . 'Export:',
    )]
    case DeployingText = 'deploying-text';

    #[Translation(
        en: 'Every page is rendered by its own route\'s controller — once more in each language, at an address that '
            . 'names it — every address is moved under the base path, and the export fails on any link to a page it '
            . 'did not write or to an anchor that page does not have. This site is that command\'s output.',
        de: 'Jede Seite wird vom Controller ihrer eigenen Route gerendert — noch einmal in jeder Sprache, unter einer '
            . 'Adresse, die sie nennt —, jede Adresse wird unter den Basispfad verschoben, und der Export scheitert an '
            . 'jedem Link auf eine Seite, die er nicht geschrieben hat, oder auf einen Anker, den diese Seite nicht '
            . 'hat. Diese Website ist die Ausgabe dieses Befehls.',
    )]
    case Exported = 'exported';
}
