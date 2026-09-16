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

    /** What a passkey form says when its authenticator did not answer — cancelled, timed out, refused. */
    #[Translation(
        en: 'The passkey did not answer, so nothing was sent. Try again.',
        de: 'Der Passkey hat nicht geantwortet, deshalb wurde nichts gesendet. Versuch es noch einmal.',
    )]
    case PasskeyUnanswered = 'passkey-unanswered';

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

    #[Translation(en: 'Unlock with a passkey', de: 'Mit Passkey entsperren')]
    case Unlock = 'unlock';

    #[Translation(en: 'Register this device', de: 'Dieses Gerät registrieren')]
    case RegisterDevice = 'register-device';

    #[Translation(
        en: 'A device is registered here and enrolled by the signing key: registering shows a code, and'
            . ' the code is what access v1 enrol takes.',
        de: 'Ein Gerät wird hier registriert und mit dem Signierschlüssel eingetragen: die Registrierung'
            . ' zeigt einen Code, und diesen Code nimmt access v1 enrol.',
    )]
    case RegisterHowTo = 'register-how-to';

    #[Translation(
        en: 'This deployment has no sign-in for browsers; it answers signed requests only.',
        de: 'Diese Installation hat keine Anmeldung für Browser; sie beantwortet nur signierte Anfragen.',
    )]
    case PasskeysOff = 'passkeys-off';

    #[Translation(en: 'That passkey did not open the admin.', de: 'Dieser Passkey hat die Verwaltung nicht geöffnet.')]
    case UnlockRefused = 'unlock-refused';

    #[Translation(en: 'That registration was not accepted.', de: 'Diese Registrierung wurde nicht angenommen.')]
    case RegistrationRefused = 'registration-refused';

    #[Translation(
        en: 'This browser is locked out, but the lock could not be recorded: a copy of its session may'
            . ' still open the admin until it runs out.',
        de: 'Dieser Browser ist ausgesperrt, aber die Sperre ließ sich nicht festhalten: eine Kopie seiner'
            . ' Sitzung öffnet die Verwaltung womöglich noch, bis sie abläuft.',
    )]
    case LockUnrecorded = 'lock-unrecorded';

    #[Translation(
        en: 'The entrance cannot count attempts, so it takes none: data/throttle/ is missing or not writable.',
        de: 'Der Eingang kann Versuche nicht zählen und nimmt daher keine an: data/throttle/ fehlt oder ist'
            . ' nicht beschreibbar.',
    )]
    case EntranceUncounted = 'entrance-uncounted';

    #[Translation(en: 'Enrolment code', de: 'Eintragungscode')]
    case EnrolmentCode = 'enrolment-code';

    #[Translation(
        en: 'Valid for ten minutes. On the machine that holds the signing key, run:',
        de: 'Zehn Minuten gültig. Auf dem Rechner mit dem Signierschlüssel ausführen:',
    )]
    case EnrolmentHowTo = 'enrolment-how-to';

    #[Translation(en: 'Fingerprint', de: 'Fingerabdruck')]
    case Fingerprint = 'fingerprint';

    #[Translation(en: 'Lock the admin', de: 'Verwaltung sperren')]
    case Logout = 'logout';

    #[Translation(en: 'Dry run', de: 'Probelauf')]
    case DryRun = 'dry-run';

    #[Translation(en: 'Apply', de: 'Ausführen')]
    case Apply = 'apply';

    #[Translation(
        en: 'Who may open the admin in a browser: the devices whose passkeys are enrolled.',
        de: 'Wer die Verwaltung im Browser öffnen darf: die Geräte, deren Passkeys eingetragen sind.',
    )]
    case ServiceAccess = 'service-access';

    #[Translation(
        en: 'Enrol a device the entrance registered, from the code it showed.',
        de: 'Ein Gerät eintragen, das der Eingang registriert hat, anhand des Codes, den er angezeigt hat.',
    )]
    case AccessEnrol = 'access-enrol';

    #[Translation(
        en: 'The devices enrolled, each with its fingerprint.',
        de: 'Die eingetragenen Geräte, jedes mit seinem Fingerabdruck.',
    )]
    case AccessPasskeys = 'access-passkeys';

    #[Translation(en: "Take one device's passkey away.", de: 'Einem Gerät seinen Passkey wieder entziehen.')]
    case AccessRevoke = 'access-revoke';

    #[Translation(
        en: 'The code the entrance showed when the device registered.',
        de: 'Der Code, den der Eingang bei der Registrierung des Geräts angezeigt hat.',
    )]
    case FieldCode = 'field-code';

    #[Translation(en: 'What to call the device: phone, laptop.', de: 'Wie das Gerät heißen soll: Handy, Laptop.')]
    case FieldName = 'field-name';

    #[Translation(en: "The passkey's credential id, as listed.", de: 'Die Kennung des Passkeys, wie aufgelistet.')]
    case FieldPasskey = 'field-passkey';

    #[Translation(
        en: 'Carry it out. Without it, a dry run that changes nothing.',
        de: 'Ausführen. Ohne das ein Probelauf, der nichts ändert.',
    )]
    case FieldApply = 'field-apply';

    #[Translation(en: 'Remove what the pushed tree leaves out.', de: 'Entfernen, was der gepushte Baum weglässt.')]
    case FieldMirror = 'field-mirror';

    #[Translation(
        en: 'The machine this runs on: what it is, what it is doing, and its files.',
        de: 'Die Maschine, auf der das läuft: was sie ist, was sie tut, und ihre Dateien.',
    )]
    case ServiceMachine = 'service-machine';

    #[Translation(
        en: 'The machine at a glance: host, hardware, memory, disks, network, sensors — and live readings.',
        de: 'Die Maschine auf einen Blick: Host, Hardware, Speicher, Laufwerke, Netz, Sensoren — und'
            . ' laufende Messwerte.',
    )]
    case MachineSystem = 'machine-system';

    #[Translation(
        en: 'The processes holding the most memory.',
        de: 'Die Prozesse, die am meisten Speicher belegen.',
    )]
    case MachineProcesses = 'machine-processes';

    #[Translation(
        en: 'Browse the files: a directory is its entries, a file what it holds.',
        de: 'Die Dateien durchsehen: ein Verzeichnis zeigt seine Einträge, eine Datei ihren Inhalt.',
    )]
    case MachineFiles = 'machine-files';

    #[Translation(
        en: "A file's bytes, shown where a browser can show them.",
        de: 'Die Bytes einer Datei, angezeigt, wo ein Browser sie anzeigen kann.',
    )]
    case MachineRaw = 'machine-raw';

    #[Translation(en: "A file's bytes, to save.", de: 'Die Bytes einer Datei, zum Speichern.')]
    case MachineDownload = 'machine-download';

    #[Translation(en: 'Keep files in a directory.', de: 'Dateien in einem Verzeichnis ablegen.')]
    case MachineUpload = 'machine-upload';

    #[Translation(en: 'Make a directory in a directory.', de: 'Ein Verzeichnis in einem Verzeichnis anlegen.')]
    case MachineFolder = 'machine-folder';

    #[Translation(en: 'Give an entry another name.', de: 'Einem Eintrag einen anderen Namen geben.')]
    case MachineRename = 'machine-rename';

    #[Translation(
        en: 'Remove a file, a link, or a directory with nothing in it.',
        de: 'Eine Datei, eine Verknüpfung oder ein leeres Verzeichnis entfernen.',
    )]
    case MachineDelete = 'machine-delete';

    #[Translation(
        en: "Run a command in a directory, with the machine's shell, and show what it printed.",
        de: 'Einen Befehl in einem Verzeichnis mit der Shell der Maschine ausführen, und zeigen, was er'
            . ' ausgegeben hat.',
    )]
    case MachineRun = 'machine-run';

    #[Translation(en: 'The files to keep here.', de: 'Die Dateien, die hier abgelegt werden.')]
    case FieldFiles = 'field-files';

    #[Translation(en: 'The name to give.', de: 'Der Name, der vergeben wird.')]
    case FieldTarget = 'field-target';

    #[Translation(en: 'The command line to run.', de: 'Die Befehlszeile, die ausgeführt wird.')]
    case FieldCommand = 'field-command';

    #[Translation(en: 'Open', de: 'Öffnen')]
    case Open = 'open';

    #[Translation(en: 'Save', de: 'Speichern')]
    case Save = 'save';

    #[Translation(en: 'Keep files here', de: 'Dateien hier ablegen')]
    case UploadHere = 'upload-here';

    #[Translation(en: 'New directory', de: 'Neues Verzeichnis')]
    case NewFolder = 'new-folder';

    #[Translation(en: 'Run a command here', de: 'Hier einen Befehl ausführen')]
    case RunHere = 'run-here';

    #[Translation(en: 'Rename', de: 'Umbenennen')]
    case Rename = 'rename';

    #[Translation(en: 'Remove', de: 'Entfernen')]
    case Remove = 'remove';

    #[Translation(en: 'Filter by name', de: 'Nach Namen filtern')]
    case Filter = 'filter';

    #[Translation(en: 'Where this is', de: 'Wo das ist')]
    case Whereabouts = 'whereabouts';

    #[Translation(en: 'Back to where it is', de: 'Zurück dorthin')]
    case BackThere = 'back-there';

    #[Translation(en: 'Name', de: 'Name')]
    case ColumnName = 'column-name';

    #[Translation(en: 'Size', de: 'Größe')]
    case ColumnSize = 'column-size';

    #[Translation(en: 'Modified', de: 'Geändert')]
    case ColumnModified = 'column-modified';

    #[Translation(en: 'Permissions', de: 'Rechte')]
    case ColumnMode = 'column-mode';

    #[Translation(en: 'Owner', de: 'Besitzer')]
    case ColumnOwner = 'column-owner';

    #[Translation(
        en: 'Only the first entries are listed; the directory holds more.',
        de: 'Nur die ersten Einträge sind aufgeführt; das Verzeichnis enthält mehr.',
    )]
    case MoreEntries = 'more-entries';

    #[Translation(
        en: 'Nothing of it is shown here: it is not a picture, a recording, a film or text.',
        de: 'Nichts davon wird hier gezeigt: es ist weder Bild noch Aufnahme, Film oder Text.',
    )]
    case NoPreview = 'no-preview';

    #[Translation(
        en: 'Only its beginning is shown; save it for the rest.',
        de: 'Nur der Anfang wird gezeigt; für den Rest speichern.',
    )]
    case PreviewCut = 'preview-cut';

    #[Translation(en: 'Live', de: 'Live')]
    case Live = 'live';

    #[Translation(
        en: 'Secret texts and files, sealed and kept behind a link.',
        de: 'Geheime Texte und Dateien, versiegelt und hinter einem Link hinterlegt.',
    )]
    case ServiceDrop = 'service-drop';

    #[Translation(
        en: 'Keeps a text or a file, sealed, and answers the link that opens it.',
        de: 'Hinterlegt einen Text oder eine Datei, versiegelt, und antwortet mit dem Link, der sie öffnet.',
    )]
    case DropCreate = 'drop-create';

    #[Translation(
        en: 'The drops kept here: when each goes, and how it opens.',
        de: 'Die hier hinterlegten Übergaben: wann jede verschwindet und wie sie sich öffnet.',
    )]
    case DropList = 'drop-list';

    #[Translation(
        en: 'Takes a drop away before it expires.',
        de: 'Nimmt eine Übergabe weg, bevor sie abläuft.',
    )]
    case DropRevoke = 'drop-revoke';

    #[Translation(en: 'Text to share', de: 'Zu teilender Text')]
    case FieldText = 'field-text';

    #[Translation(en: 'Or a file', de: 'Oder eine Datei')]
    case FieldFile = 'field-file';

    #[Translation(en: 'A name to save it under', de: 'Ein Name, unter dem sie gespeichert wird')]
    case FieldFilename = 'field-filename';

    #[Translation(
        en: 'How long it is kept — 30m, 12h, 7d; a day where left empty',
        de: 'Wie lange sie bleibt — 30m, 12h, 7d; ein Tag, wenn leer',
    )]
    case FieldLifetime = 'field-lifetime';

    #[Translation(en: 'Gone once it has been read', de: 'Fort, sobald sie gelesen wurde')]
    case FieldOnce = 'field-once';

    #[Translation(en: 'A password it needs as well', de: 'Ein Passwort, das sie zusätzlich braucht')]
    case FieldPassword = 'field-password';

    #[Translation(en: 'The link that opens it', de: 'Der Link, der sie öffnet')]
    case DropLink = 'drop-link';

    #[Translation(en: 'Take it away', de: 'Wegnehmen')]
    case DropRevokeLink = 'drop-revoke-link';

    #[Translation(en: 'Make one', de: 'Eine anlegen')]
    case DropMake = 'drop-make';

    #[Translation(en: 'Every drop kept here', de: 'Alle hier hinterlegten Übergaben')]
    case DropsKept = 'drops-kept';
}
