<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The TagName interface. One element name an {@link Element} may be.
 *
 * Two enums implement it, split the way {@link \Phpanta\Http\SecurityHeader} and
 * {@link \Phpanta\Http\ResponseHeader} are: {@link Tag} is this site's own vocabulary — every case
 * is a custom element that has to be registered client-side, is mirrored in `assets/ts/model/`, and
 * is asserted against the served markup. {@link HtmlTag} is the standard elements, which the browser
 * already knows and which no test needs to pin.
 */
interface TagName
{
    /**
     * The tag name as it appears in the markup.
     *
     * @return string
     */
    public function tagName(): string;

    /**
     * True if the element has no closing tag and can hold no children.
     *
     * `<img>` and `<meta>` are void; every custom element is not, because a custom element with no
     * closing tag is a parse error the browser recovers from silently by swallowing what follows.
     *
     * @return bool
     */
    public function isVoid(): bool;
}
