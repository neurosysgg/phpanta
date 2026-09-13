<?php

declare(strict_types=1);

namespace Phpanta\Text;

/**
 * The Language enum. A language this site is written in.
 *
 * **Decided once per request**, by {@link \Phpanta\Http\Request::language()}: the visitor's `lang`
 * cookie where it names a language this site has, else their `Accept-Language`, else English. It
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
 * spelling and date order that this site does not make good on, and a tag claiming more than it
 * delivers is worse than one claiming less.
 *
 * **Mirrored in `assets/ts/model/Language.ts`**, because the client writes a few words of its own —
 * the consent gate, the title of a player — and reads the language of the page off `<html lang>`
 * to write them in. `enum-parity.test.mjs` compares the two case for case.
 */
enum Language: string
{
    /** The site's own language, and the answer to every request that asks for neither. */
    case English = 'en';

    /**
     * The site's second language, and the one its legal obligations are met in.
     *
     * § 5 DDG and § 18 Abs. 2 MStV are met in German, which is why the imprint and the privacy
     * policy always carry their German half, whichever language leads. See
     * {@link \NeuroSYS\View\ImprintView}.
     */
    case German = 'de';

    /**
     * The language's name in itself — `deutsch`, `english` — which is how the switch in the footer
     * names it, so a visitor who cannot read the page can still find their own language on it.
     * Lower case, the way the site writes its navigation.
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
