<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The MetaName enum. What a `<meta name>` names.
 *
 * An attribute *value* rather than a name, the same arrangement as {@link LinkRel},
 * {@link LinkTarget}, {@link ScriptType} and {@link MediaPreload}: a fixed vocabulary, so it is a
 * case and not a string. Server-only like all four — nothing client-side reads a `<meta>` here —
 * so there is no TypeScript mirror and none is wanted.
 *
 * **Both cases fail the same way, which is not at all.** A `<meta>` whose name nothing recognises
 * is not an error and not a warning; it is an element the browser lays out as nothing and moves
 * past. Misspell {@link self::Viewport} and every phone renders the site at a 980px viewport,
 * zoomed out, with the stylesheet's media queries answering for a screen nobody is holding —
 * a layout bug three layers away from its cause, and nothing in any console. Misspell
 * {@link self::Description} and the site simply stops describing itself to anything that reads
 * pages, which shows up in a search result weeks later or never.
 *
 * That is the same shape as the `modulepreload` argument on {@link LinkRel}: a value whose absence
 * costs something real and reports nothing. {@link HtmlAttribute::Name} is used nowhere else on
 * this site, so this enum is the whole vocabulary of that attribute.
 */
enum MetaName: string
{
    /**
     * How wide the browser should pretend the screen is.
     *
     * Paired with {@link ViewportContent}, which is the value half: a descriptor list with its own
     * grammar, which is what earns a class — a case cannot hold one.
     */
    case Viewport = 'viewport';

    /** The sentence a search result or a link preview quotes, in the page's language. See `Layout`. */
    case Description = 'description';
}
