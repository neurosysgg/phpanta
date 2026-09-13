<?php

declare(strict_types=1);

namespace Phpanta\Text;

/**
 * The AdminText enum. The admin's words: what each service and action is for, and the few the pages
 * around them say.
 *
 * A catalog of its own rather than cases on {@link FrameworkText}, which holds the handful of
 * sentences the framework says on any site's behalf; these are what a listing says about each thing
 * the admin can do, and there is one of them per action. A new action is a case here as well as in
 * its service's enum, and the listing is what would show it missing.
 */
enum AdminText: string implements Translatable
{
    use Translated;

    /** The admin's name, as its pages are titled. */
    #[Translation(en: 'admin', de: 'Verwaltung')]
    case Admin = 'admin';

    /** What the entrance says to a visitor it cannot yet let in. */
    #[Translation(
        en: 'Everything here needs a credential.',
        de: 'Alles hier braucht eine Berechtigung.',
    )]
    case Entrance = 'entrance';

    /** An action that only reads. */
    #[Translation(en: 'reads', de: 'liest')]
    case Reads = 'reads';

    /** An action that writes, unless it is a dry run. */
    #[Translation(en: 'writes', de: 'schreibt')]
    case Writes = 'writes';

    /** An action a browser cannot run — a push, which needs the tree it sends. */
    #[Translation(en: 'CLI only', de: 'nur per CLI')]
    case CliOnly = 'cli-only';

    #[Translation(en: 'Deploying, and asking what is deployed.', de: 'Ausliefern, und fragen, was ausgeliefert ist.')]
    case ServiceUpdate = 'service-update';

    #[Translation(
        en: 'Whether this host meets what this site needs of it.',
        de: 'Ob dieser Host erfüllt, was diese Seite von ihm braucht.',
    )]
    case ServiceHealth = 'service-health';

    #[Translation(
        en: 'What this host has, with no verdict on any of it.',
        de: 'Was dieser Host hat, ohne ein Urteil darüber.',
    )]
    case ServiceCapability = 'service-capability';

    #[Translation(en: 'The first version, and the current one.', de: 'Die erste Version, und die aktuelle.')]
    case VersionOne = 'version-one';

    #[Translation(
        en: 'Write a pushed tree, and remove what it leaves out.',
        de: 'Einen gepushten Baum schreiben, und entfernen, was er weglässt.',
    )]
    case UpdatePatch = 'update-patch';

    #[Translation(
        en: 'What is deployed: the last push, the build stamp, the PHP version.',
        de: 'Was ausgeliefert ist: der letzte Push, der Build-Stempel, die PHP-Version.',
    )]
    case UpdateVersion = 'update-version';

    #[Translation(
        en: 'Put back the release the last push replaced.',
        de: 'Das Release zurückholen, das der letzte Push ersetzt hat.',
    )]
    case UpdateRollback = 'update-rollback';

    #[Translation(
        en: "Measure what this host's filesystem lets a push do.",
        de: 'Messen, was das Dateisystem dieses Hosts einem Push erlaubt.',
    )]
    case UpdateProbe = 'update-probe';

    #[Translation(
        en: 'Every requirement, in every area, with a tally.',
        de: 'Jede Anforderung, in jedem Bereich, mit einer Bilanz.',
    )]
    case HealthReport = 'health-report';

    #[Translation(en: "The interpreter's own version.", de: 'Die Version des Interpreters.')]
    case HealthRuntime = 'health-runtime';

    #[Translation(
        en: 'The extensions, each proved by being used.',
        de: 'Die Erweiterungen, jede durch Benutzen geprüft.',
    )]
    case HealthExtensions = 'health-extensions';

    #[Translation(en: 'The php.ini floors.', de: 'Die Untergrenzen der php.ini.')]
    case HealthSettings = 'health-settings';

    #[Translation(
        en: 'This installation: its webroot, and the files it cannot run without.',
        de: 'Diese Installation: ihr Webroot, und die Dateien, ohne die sie nicht läuft.',
    )]
    case HealthDeployment = 'health-deployment';

    #[Translation(
        en: 'The interpreter, the machine under it, and its clock.',
        de: 'Der Interpreter, die Maschine darunter, und ihre Uhr.',
    )]
    case CapabilityRuntime = 'capability-runtime';

    #[Translation(
        en: 'Every extension the engine has loaded, and its version.',
        de: 'Jede Erweiterung, die die Engine geladen hat, und ihre Version.',
    )]
    case CapabilityExtensions = 'capability-extensions';

    #[Translation(en: 'Every php.ini directive, and its value.', de: 'Jede Direktive der php.ini, und ihr Wert.')]
    case CapabilitySettings = 'capability-settings';

    #[Translation(
        en: 'Where this installation serves from, and which of its files are there.',
        de: 'Von wo diese Installation ausliefert, und welche ihrer Dateien da sind.',
    )]
    case CapabilityDeployment = 'capability-deployment';

    #[Translation(
        en: "Where a diagnostic goes, the last one that got there, and the log's tail.",
        de: 'Wohin eine Meldung geht, die letzte, die dort ankam, und das Ende des Logs.',
    )]
    case CapabilityErrors = 'capability-errors';

    #[Translation(
        en: 'Carry it out. Without it, a dry run that changes nothing.',
        de: 'Ausführen. Ohne das ein Probelauf, der nichts ändert.',
    )]
    case FieldApply = 'field-apply';

    #[Translation(en: 'Remove what the pushed tree leaves out.', de: 'Entfernen, was der gepushte Baum weglässt.')]
    case FieldMirror = 'field-mirror';
}
