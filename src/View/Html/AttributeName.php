<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The AttributeName interface. One attribute name an {@link Element} may carry.
 *
 * Implemented by an enum per custom element, which a site writes, plus {@link HtmlAttribute} for
 * the standard ones and {@link LinkAttribute} for the framework's own, so `data-no-spa` and `href`
 * are both a case rather than a string typed out at each call site. Same shape as
 * {@link \Phpanta\Http\Security\CspSource}: the interface is what lets {@link Element} take any
 * element's attributes without knowing which element it is building.
 *
 * Two methods, the same split {@link TagName} has: what it is called, and what kind of thing it is.
 * {@link TagName::isVoid()} is what stops `<img>` being given children; {@link self::isUrl()} is
 * what stops an attribute the browser will *navigate or fetch* being handed a `javascript:` URL.
 */
interface AttributeName
{
    /**
     * The attribute name as it appears in the markup.
     *
     * @return string
     */
    public function attribute(): string;

    /**
     * True if the browser resolves this attribute's value as a URL.
     *
     * Answered per case rather than per enum, because `href` and `class` live in the same one. It
     * decides whether {@link Element} scheme-checks the value on the way out — escaping is the
     * wrong tool there and always was: `javascript:alert(1)` contains not one character
     * htmlspecialchars touches, so it arrives in the page exactly as written.
     *
     * Say true for anything the browser dereferences on its own *or* that a custom element assigns
     * to a `.src`/`.href`, which is the same thing one layer along — a custom element's `fallback`
     * is not a URL to the HTML parser, but the element's script makes it one.
     *
     * @return bool
     */
    public function isUrl(): bool;
}
