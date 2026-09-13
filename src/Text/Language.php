<?php

declare(strict_types=1);

namespace Phpanta\Text;

/**
 * The Language enum. A language the framework can write, and an app may be written in.
 *
 * **Decided once per request**, by {@link \Phpanta\Http\Request::language()}: the visitor's `lang`
 * cookie where it names a language the app offers, else their `Accept-Language`, else the app's
 * default — see {@link Languages}. It
 * lives under `Text` rather than beside the markup vocabulary it began in, because it stopped being
 * only an attribute value: the request decides it, and the pages are written in it.
 *
 * Still an attribute *value* as well — {@link \Phpanta\View\Html\Element::attr()} takes it for
 * `lang` like any backed enum — and **a wrong `lang` is its failure mode, which is not at all.**
 * Nothing validates a language tag: a browser meeting `lang="eng"` or `lang="en-"` does not warn, it
 * simply stops having an answer for the questions the attribute exists to answer. A screen reader
 * announces German text in an English voice, or falls back to the interface language; hyphenation
 * breaks words at the wrong points; `:lang()` selectors stop matching; a translation offer never
 * appears. Every one of those is discovered by somebody who is not looking at the markup.
 *
 * Bare primary subtags, deliberately — `en` rather than `en-GB`. A region says something about
 * spelling and date order that no page here makes good on, and a tag claiming more than it
 * delivers is worse than one claiming less.
 *
 * **Mirrored in `assets/ts/model/Language.ts`**, because the client writes a few words of its own
 * and reads the language of the page off `<html lang>` to write them in. `enum-parity.test.mjs`
 * compares the two case for case.
 */
enum Language: string
{
    /** English: the text every {@link Translation} is required to have, and falls back to. */
    case English = 'en';

    /**
     * German, which a {@link Translation} may carry beside the English.
     *
     * A site that owes legal notices in German — an imprint, a privacy policy — keeps their German
     * half whichever language leads, by marking that half with its own `lang`.
     */
    case German = 'de';

    /**
     * The language's name in itself — `deutsch`, `english` — which is how a language switch names
     * it, so a visitor who cannot read the page can still find their own language on it. Lower
     * case; a site that wants capitals styles them.
     *
     * @return string
     */
    public function endonym(): string
    {
        return match ($this) {
            self::English => 'english',
            self::German  => 'deutsch',
        };
    }
}
