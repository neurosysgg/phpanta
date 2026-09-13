<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

use Phpanta\Http\HeaderValue;

/**
 * The CrossOriginOpenerPolicy enum. Whether a page shares its browsing context group with windows of
 * other origins — the `window.opener` a popup gets, and the one this page gets when another site
 * opens it.
 *
 * `same-origin`, by default: a site that opens a link to a file host in a new tab, or is opened by
 * one, keeps no handle across the origin line either way, which is what closes tab-nabbing and the
 * cross-window leaks that ride on a shared group. A site that needs a popup to talk back — a
 * third-party sign-in window — says `same-origin-allow-popups` in {@link \Phpanta\App::crossOriginOpenerPolicy()}.
 */
enum CrossOriginOpenerPolicy: string implements HeaderValue
{
    /** Only windows of this origin share a group with this page. */
    case SameOrigin = 'same-origin';

    /** As {@link self::SameOrigin}, except a popup this page opens keeps its opener. */
    case SameOriginAllowPopups = 'same-origin-allow-popups';

    /** The browser's default: no isolation. Named so a site that must have it says so. */
    case UnsafeNone = 'unsafe-none';

    /**
     * @return string
     */
    public function render(): string
    {
        return $this->value;
    }
}
