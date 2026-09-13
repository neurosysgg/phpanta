<?php

declare(strict_types=1);

namespace PhpantaSite\Text;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * The rules page's words. A heading's case is keyed by its anchor.
 */
enum RulesText: string implements Translatable
{
    use Translated;

    #[Translation(
        en: 'Each of these replaces a habit that fails silently with one that fails loudly. They are the reason the '
            . 'code looks the way it does.',
        de: 'Jede dieser Regeln ersetzt eine Gewohnheit, die still scheitert, durch eine, die laut scheitert. Sie sind '
            . 'der Grund, warum der Code so aussieht, wie er aussieht.',
    )]
    case Lede = 'lede';

    #[Translation(en: 'Nothing builds HTML from a string', de: 'Nichts baut HTML aus einem String')]
    case NoHtmlFromStrings = 'no-html-from-strings';

    #[Translation(
        en: 'A view returns a {node}. Only {element} and {doctype} ever write a {bracket}, and escaping and the '
            . 'URL-scheme check both live in {render} — so they hold however an element was built, and a '
            . '{javascript} link is refused where it would be written rather than rendered.',
        de: 'Eine View liefert einen {node}. Nur {element} und {doctype} schreiben je ein {bracket}, und Escaping und '
            . 'die Prüfung des URL-Schemas stecken beide in {render} — also gelten sie, wie auch immer ein Element '
            . 'gebaut wurde, und ein {javascript}-Link wird abgelehnt, wo er geschrieben würde, statt gerendert zu '
            . 'werden.',
    )]
    case NoHtmlFromStringsText = 'no-html-from-strings-text';

    #[Translation(
        en: 'Hand-authored HTML is parsed, never trusted',
        de: 'Handgeschriebenem HTML wird nie vertraut, es wird geparst',
    )]
    case ParsedNotTrusted = 'parsed-not-trusted';

    #[Translation(
        en: 'It enters the tree only through {containingHtml}, which parses it against the app\'s vocabulary and '
            . 'refuses any tag or attribute the app did not declare. Nothing a request can influence is ever parsed '
            . '— and this page is not parsed at all: it is a tree its view builds, in each of its languages.',
        de: 'Es gelangt nur über {containingHtml} in den Baum, das es gegen das Vokabular der App parst und jedes Tag '
            . 'und Attribut ablehnt, das die App nicht deklariert hat. Nichts, worauf eine Anfrage Einfluss hat, wird '
            . 'je geparst — und diese Seite wird gar nicht geparst: Sie ist ein Baum, den ihre View baut, in jeder '
            . 'ihrer Sprachen.',
    )]
    case ParsedNotTrustedText = 'parsed-not-trusted-text';

    #[Translation(
        en: 'Visible text is translatable, and a view never names a language',
        de: 'Sichtbarer Text ist übersetzbar, und eine View nennt nie eine Sprache',
    )]
    case Translatable = 'translatable';

    #[Translation(
        en: 'A catalog is an enum, each case carries its words in every language the app offers, and the tree '
            . 'resolves each one against the nearest {lang} when it renders. A sentence with a link or a piece of '
            . 'code in it is a {sentence}, whose parts each language places where its word order wants them. A '
            . 'translatable with no {lang} above it throws rather than guessing.',
        de: 'Ein Katalog ist ein Enum, jeder Case trägt seine Worte in jeder Sprache, die die App anbietet, und der '
            . 'Baum löst jeden beim Rendern gegen das nächstgelegene {lang} auf. Ein Satz mit einem Link oder einem '
            . 'Stück Code darin ist ein {sentence}, dessen Teile jede Sprache dorthin setzt, wo ihre Wortstellung sie '
            . 'haben will. Übersetzbarer Text ohne ein {lang} darüber wirft eine Exception, statt zu raten.',
    )]
    case TranslatableText = 'translatable-text';

    #[Translation(en: 'Names and values are typed', de: 'Namen und Werte sind typisiert')]
    case Typed = 'typed';

    #[Translation(
        en: 'A header is a {headerName} case and a {headerValue}. An attribute is an {attributeName} case. An '
            . 'address is a {path} case, and a link is {to}, never a concatenation. A value with a grammar is a '
            . 'class, and a fixed vocabulary is an enum — so a misspelled name is an error where it is written, not '
            . 'a default somewhere else.',
        de: 'Ein Header ist ein {headerName}-Case und ein {headerValue}. Ein Attribut ist ein {attributeName}-Case. '
            . 'Eine Adresse ist ein {path}-Case, und ein Link ist {to}, nie eine Verkettung. Ein Wert mit einer '
            . 'Grammatik ist eine Klasse, ein festes Vokabular ist ein Enum — also ist ein falsch geschriebener Name '
            . 'ein Fehler dort, wo er geschrieben wird, und kein Standardwert irgendwo anders.',
    )]
    case TypedText = 'typed-text';

    #[Translation(en: 'A group is a collection', de: 'Eine Gruppe ist eine Collection')]
    case Collections = 'collections';

    #[Translation(
        en: 'A group crossing a public boundary is an immutable, lazy {collection} or {searchable}: {with} copies, '
            . 'and a chain of steps fuses into one pass at the first call that needs a result.',
        de: 'Eine Gruppe, die eine öffentliche Grenze überquert, ist eine unveränderliche, lazy ausgewertete '
            . '{collection} oder {searchable}: {with} kopiert, und eine Kette von Schritten verschmilzt beim ersten '
            . 'Aufruf, der ein Ergebnis braucht, zu einem einzigen Durchlauf.',
    )]
    case CollectionsText = 'collections-text';

    #[Translation(en: 'Five habits are refused', de: 'Fünf Gewohnheiten werden abgelehnt')]
    case FiveHabits = 'five-habits';

    #[Translation(en: 'a bare {array} in a declared type,', de: 'ein nacktes {array} in einem deklarierten Typ,')]
    case BareArray = 'bare-array';

    #[Translation(en: 'a bare string that is really a name,', de: 'ein nackter String, der eigentlich ein Name ist,')]
    case BareString = 'bare-string';

    #[Translation(
        en: 'an {call} call a collection has a member for,',
        de: 'ein {call}-Aufruf, für den eine Collection eine eigene Methode hat,',
    )]
    case BareCall = 'bare-call';

    #[Translation(
        en: '{at}, which silences every diagnostic in an expression, and',
        de: '{at}, das jede Meldung in einem Ausdruck zum Schweigen bringt, und',
    )]
    case Suppression = 'suppression';

    #[Translation(
        en: 'an SPL exception thrown where one of the framework\'s own belongs.',
        de: 'eine SPL-Exception, geworfen, wo eine des Frameworks selbst hingehört.',
    )]
    case SplException = 'spl-exception';

    #[Translation(
        en: 'The first three may be excused, by an attribute that carries its reason: {bareArray}, {bareString}, '
            . '{bareCall}.',
        de: 'Die ersten drei dürfen entschuldigt werden, durch ein Attribut, das seinen Grund trägt: {bareArray}, '
            . '{bareString}, {bareCall}.',
    )]
    case Excused = 'excused';

    #[Translation(
        en: 'A result that matters cannot be dropped',
        de: 'Ein Ergebnis, das zählt, kann nicht verloren gehen',
    )]
    case NoDroppedResults = 'no-dropped-results';

    #[Translation(
        en: 'Builders and queries carry {noDiscard} with a message, so a copy-returning call whose copy is thrown '
            . 'away raises a warning — and the test suites fail on warnings.',
        de: 'Builder und Abfragen tragen {noDiscard} mit einer Nachricht, also löst ein kopierender Aufruf, dessen '
            . 'Kopie weggeworfen wird, eine Warnung aus — und die Testsuiten scheitern an Warnungen.',
    )]
    case NoDroppedResultsText = 'no-dropped-results-text';

    #[Translation(
        en: 'Nothing ends a request but the app, and every decision returns',
        de: 'Nichts beendet eine Anfrage außer der App, und jede Entscheidung kehrt zurück',
    )]
    case EveryDecisionReturns = 'every-decision-returns';

    #[Translation(
        en: 'A response\'s {answer} is an {answerClass}, and {handle} answers a whole request without sending it. '
            . 'Only {run} sends. A gate\'s refusal is a value it returns, marked {noDiscard} so a dropped one fails '
            . 'the suite, and the caller returns it in turn. Nothing calls {exit}. So every answer, a 401, a 303 or '
            . 'a 405 included, is a status, headers and a body a test can read in-process, through {testRequest}.',
        de: 'Das {answer} einer Antwort ist ein {answerClass}, und {handle} beantwortet eine ganze Anfrage, ohne sie '
            . 'zu senden. Nur {run} sendet. Die Ablehnung eines Gates ist ein Wert, den es zurückgibt, markiert mit '
            . '{noDiscard}, sodass eine fallengelassene die Suite scheitern lässt, und der Aufrufer gibt sie '
            . 'seinerseits zurück. Nichts ruft {exit} auf. So ist jede Antwort, ein 401, ein 303 oder ein 405 '
            . 'eingeschlossen, ein Status, Header und ein Body, die ein Test im selben Prozess lesen kann, '
            . 'über {testRequest}.',
    )]
    case EveryDecisionReturnsText = 'every-decision-returns-text';

    #[Translation(
        en: 'The full argument for each is in {guidelines} and {claude}.',
        de: 'Die vollständige Begründung für jede steht in {guidelines} und {claude}.',
    )]
    case FullArgument = 'full-argument';
}
