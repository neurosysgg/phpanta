<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

use Phpanta\Support\Collection;

/**
 * The LinkRel enum. The relationships a page declares on a `<link>` or an `<a>`.
 *
 * A **value** enum rather than a name one, so it implements nothing — the same shape as
 * {@link LinkTarget} and {@link \Phpanta\Http\RequestedWith}, which sits next to the header it fills
 * and exists, in its own words, to make the fact a type rather than a comment next to a magic
 * string. {@link Element::attr()} already unwraps any `BackedEnum` to its value, so a case passes
 * straight through with no change to the attribute API.
 *
 * The two halves of this enum fail in opposite ways, which is why both are here:
 *
 * - **`Stylesheet` and `ModulePreload` are what a resource is *for*.** Misspell either and the
 *   browser fetches nothing and says nothing: an unstyled page, or a list of preload hints that
 *   quietly stop preloading and leave the module waterfall they exist to flatten. Neither reaches
 *   a console.
 * - **`NoOpener` and `NoReferrer` are a security boundary**, on every outbound link that opens in a
 *   new tab. `noopener` is what stops the opened page reaching back through `window.opener`;
 *   `noreferrer` keeps the page's URL off the wire, the same claim
 *   {@link \Phpanta\Http\Security\ReferrerPolicy} makes for everything else. A typo in either is a
 *   protection that silently is not there, on a page that looks identical.
 *
 * `External` is neither — it is a statement about the link for anyone reading the markup.
 */
enum LinkRel: string
{
    /** The one stylesheet the shell loads. */
    case Stylesheet = 'stylesheet';

    /**
     * A module to fetch, parse, compile and put in the module map before `main.js` asks for it.
     *
     * Deliberately not `preload` with `as="script"`: that one fetches the bytes and stops there,
     * so the module is still parsed and instantiated on demand.
     */
    case ModulePreload = 'modulepreload';

    /** Deny the opened page a `window.opener` handle back to this one. */
    case NoOpener = 'noopener';

    /** Send no `Referer` when following the link. */
    case NoReferrer = 'noreferrer';

    /** Says out loud that the link leaves this site. */
    case External = 'external';

    /** The same page in another language, named by the link's `hreflang`. */
    case Alternate = 'alternate';

    /** Asks a crawler not to follow the link — the admin's, which leads nowhere a stranger can go. */
    case NoFollow = 'nofollow';

    /**
     * Several relationships as one attribute value: `noopener noreferrer external`.
     *
     * `rel` is a space-separated token list, so it is the one attribute value here that is
     * a *set* rather than a single fact. Built here rather than at the call site for the reason
     * {@link \Phpanta\Http\Allow} builds its header value from the enum rather than at the call
     * site — a list assembled by hand is a list that can disagree with the enum it came from.
     *
     * Order is the order given, because that is the order the markup reads in and the tests pin.
     *
     * @param self ...$relations
     * @return string
     */
    public static function tokens(self ...$relations): string
    {
        return new Collection(self::class)
            ->with(...$relations)
            ->map(static fn (self $relation): string => $relation->value)
            ->join(' ');
    }
}
