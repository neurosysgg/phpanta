<?php

declare(strict_types=1);

namespace Phpanta\Text;

/**
 * The FrameworkText enum. The few words the framework says itself, whatever site it runs.
 *
 * Only what framework code says without a site's words around it — a plain-text body the router or
 * the API controller writes before any page is involved, and what a {@link \Phpanta\Form\Rule}
 * says of a field it refuses. Everything else a page says is the site's, in the site's own catalogs;
 * this is the one catalog a site inherits rather than writes, which is why it holds so little.
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

    /** The body of the 413 a request larger than the host takes is answered with — see Router. */
    #[Translation(en: 'This request is too large.', de: 'Diese Anfrage ist zu groß.')]
    case ContentTooLarge = 'content-too-large';

    /** The body of the 403 a write without its form token is refused with — see CsrfGuard. */
    #[Translation(
        en: 'This form has expired. Please go back, reload the page and send it again.',
        de: 'Dieses Formular ist abgelaufen. Bitte gehe zurück, lade die Seite neu und sende es noch einmal.',
    )]
    case CsrfRefused = 'csrf-refused';

    /**
     * The same refusal where a {@link \Phpanta\Form\Form} reads it: shown at the top of the form,
     * which renders again blank with a token that will work — so it asks for the form again rather
     * than for a reload.
     */
    #[Translation(
        en: 'This form had expired, so nothing in it was kept. Please fill it in again and send it.',
        de: 'Dieses Formular war abgelaufen, deshalb wurde nichts davon übernommen. '
            . 'Bitte fülle es noch einmal aus und sende es.',
    )]
    case FormExpired = 'form-expired';

    /**
     * What a login form says of a name and a password that do not open a session — one sentence for
     * a name the site does not know and a password that is wrong, which is Login's guarantee.
     */
    #[Translation(
        en: 'That name and password do not match.',
        de: 'Name und Passwort passen nicht zusammen.',
    )]
    case LoginRefused = 'login-refused';

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

    /** A field that has to be filled in and was not — see Required. */
    #[Translation(en: 'Please fill this in.', de: 'Bitte fülle das aus.')]
    case FieldRequired = 'field-required';

    /** A field longer than it may be — see MaxLength. */
    #[Translation(
        en: 'Please use at most {max, plural, one {# character} other {# characters}}.',
        de: 'Bitte verwende höchstens {max, number} Zeichen.',
    )]
    case FieldTooLong = 'field-too-long';

    /** A field that is not an email address — see Email. */
    #[Translation(en: 'Please enter an email address.', de: 'Bitte gib eine E-Mail-Adresse ein.')]
    case FieldNotEmail = 'field-not-email';

    /** A field that is not a whole number — see WholeNumber. */
    #[Translation(en: 'Please enter a whole number.', de: 'Bitte gib eine ganze Zahl ein.')]
    case FieldNotWholeNumber = 'field-not-whole-number';

    /** A field that names none of its choices — see OneOf. */
    #[Translation(en: 'Please choose one of the options.', de: 'Bitte wähle eine der Optionen.')]
    case FieldNotAChoice = 'field-not-a-choice';

    /** A file larger than it may be, or than the host takes — see MaxBytes and Form::read(). */
    #[Translation(en: 'This file is too large.', de: 'Diese Datei ist zu groß.')]
    case FileTooLarge = 'file-too-large';

    /** The empty first option of a choice, which is what makes a required one ask — see Form. */
    #[Translation(en: '— choose —', de: '— auswählen —')]
    case FieldChoose = 'field-choose';
}
