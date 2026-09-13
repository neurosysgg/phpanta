<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The LinkTarget enum. Where a link opens.
 *
 * One case, like {@link \Phpanta\Http\RequestedWith} and
 * {@link \Phpanta\Http\Security\ContentTypeOptions} — it exists to make the value a type, not to
 * offer a choice. Every other link on this site opens where it is, which is the absence of this
 * attribute rather than a case here.
 *
 * `_blank` travels with {@link LinkRel::NoOpener}: opening in a new browsing context is what gives
 * the opened page a `window.opener` handle in the first place, so the two are written together at
 * `Layout::profileLink()` and neither is much use without the other.
 */
enum LinkTarget: string
{
    /** A new tab or window. Only the footer's outbound profile links use it. */
    case Blank = '_blank';
}
