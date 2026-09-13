<?php

declare(strict_types=1);

namespace Phpanta\Text;

/**
 * The FrameworkText enum. The few words the framework says itself, whatever site it runs.
 *
 * Only what framework code sends without a site's view around it — a plain-text body the router or
 * the API controller writes before any page is involved. Everything a page says is the site's, in
 * the site's own catalogs; this is the one catalog a site inherits rather than writes, which is why
 * it holds so little.
 */
enum FrameworkText: string implements Translatable
{
    use Translated;

    /** The body of every 405, whichever of the two paths sent it — see UnroutedController. */
    #[Translation(en: 'This site is read-only.', de: 'Diese Seite ist schreibgeschützt.')]
    case ReadOnly = 'read-only';

    /** The body of the 503 a site in maintenance answers every page with — see Maintenance. */
    #[Translation(
        en: 'This site is down for maintenance. Please try again shortly.',
        de: 'Diese Seite wird gerade gewartet. Bitte versuche es gleich noch einmal.',
    )]
    case Maintenance = 'maintenance';

    /** The body of the 400 a request whose input could not be read is answered with — see Router. */
    #[Translation(en: 'This request could not be read.', de: 'Diese Anfrage konnte nicht gelesen werden.')]
    case BadRequest = 'bad-request';

    /** The body of the 403 a write without its form token is refused with — see CsrfGuard. */
    #[Translation(
        en: 'This form has expired. Please go back, reload the page and send it again.',
        de: 'Dieses Formular ist abgelaufen. Bitte gehe zurück, lade die Seite neu und sende es noch einmal.',
    )]
    case CsrfRefused = 'csrf-refused';

    /** The body of the 403 a write behind a login is refused with, without one — see LoginGate. */
    #[Translation(en: 'Please log in first.', de: 'Bitte melde dich zuerst an.')]
    case LoginRequired = 'login-required';

    /** The title of the page a fault is shown on in development — see FaultPage. */
    #[Translation(en: 'Something broke', de: 'Etwas ist kaputtgegangen')]
    case Fault = 'fault';

    /** The body of the 429 an address over its limit is answered with — see RateLimit. */
    #[Translation(
        en: 'Too many requests. Please wait a moment and try again.',
        de: 'Zu viele Anfragen. Bitte warte einen Moment und versuche es dann noch einmal.',
    )]
    case TooManyRequests = 'too-many-requests';
}
