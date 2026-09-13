<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The ViewportWidth enum. How wide the browser is told to pretend the screen is.
 *
 * One case, like {@link LinkTarget} — it exists to make the descriptor a type, not to offer a
 * choice. The other thing the `width` descriptor accepts is a pixel count, which is a number rather
 * than a name and would be a second parameter on {@link ViewportContent} rather than a case here.
 *
 * **`ViewportWidth` and not `Width`**, because {@link HtmlAttribute::Width} is an attribute *name*
 * in this same namespace and a shell would import both. The two mean entirely
 * different things and would sit four lines apart in the import list.
 *
 * The failure is the one {@link MetaName::Viewport} describes and is worth repeating here, since
 * this is the half that actually carries the word: a `<meta name="viewport">` whose content the
 * browser cannot parse is not an error and not a warning. It is ignored, and every phone falls back
 * to a 980px layout viewport, zoomed out, with the stylesheet's media queries answering for a
 * screen nobody is holding.
 */
enum ViewportWidth: string
{
    /** The width of the device's own screen — what every responsive page asks for. */
    case Device = 'device-width';
}
