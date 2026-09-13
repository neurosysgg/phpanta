<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

/**
 * The CspDirective enum. The Content-Security-Policy directives the site sets.
 *
 * Backed by the literal directive name. Cases are declared in the order they should be
 * emitted — {@link ContentSecurityPolicy} renders in insertion order, and `default-src`
 * reads first because everything after it is a narrowing.
 */
enum CspDirective: string
{
    /** The fallback for every fetch directive not named explicitly. */
    case DefaultSrc = 'default-src';

    /** Where scripts may come from. The directive that actually stops XSS. */
    case ScriptSrc = 'script-src';

    /** Where stylesheets and inline styles may come from. */
    case StyleSrc = 'style-src';

    /** Where images may come from. */
    case ImgSrc = 'img-src';

    /** What may be loaded into an `<iframe>`. */
    case FrameSrc = 'frame-src';

    /** Where `fetch()`, `XMLHttpRequest`, `EventSource` and WebSockets may connect. */
    case ConnectSrc = 'connect-src';

    /** Where `<audio>` and `<video>` may load from. */
    case MediaSrc = 'media-src';

    /** Where an `@font-face` may load from. */
    case FontSrc = 'font-src';

    /** What `<base href>` may be set to — otherwise an injected tag can re-root every relative URL. */
    case BaseUri = 'base-uri';

    /** Where a form may submit to. */
    case FormAction = 'form-action';

    /** Who may frame *us*. The modern replacement for `X-Frame-Options`. */
    case FrameAncestors = 'frame-ancestors';

    /** `<object>`, `<embed>`, `<applet>`. Legacy plugin surface; always 'none'. */
    case ObjectSrc = 'object-src';
}
