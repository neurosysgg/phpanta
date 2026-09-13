<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

use Phpanta\Http\HeaderValue;

/**
 * The ReferrerPolicy enum. The values `Referrer-Policy` accepts, per the W3C Referrer Policy spec.
 *
 * Only one is used, but the rest are here so switching is a one-token change rather than a
 * re-read of the spec — and so a typo is a parse error instead of a header the browser ignores.
 */
enum ReferrerPolicy: string implements HeaderValue
{
    /** Never send a referrer. */
    case NoReferrer = 'no-referrer';

    /** Send one, but drop it on an HTTPS → HTTP downgrade. */
    case NoReferrerWhenDowngrade = 'no-referrer-when-downgrade';

    /** Send the origin only, never the path. */
    case Origin = 'origin';

    /** Full URL same-origin, bare origin cross-origin. */
    case OriginWhenCrossOrigin = 'origin-when-cross-origin';

    /** Full URL, but only same-origin; nothing cross-origin. */
    case SameOrigin = 'same-origin';

    /** Origin only, and nothing at all on a downgrade. */
    case StrictOrigin = 'strict-origin';

    /** Full URL same-origin, bare origin cross-origin, nothing on a downgrade. */
    case StrictOriginWhenCrossOrigin = 'strict-origin-when-cross-origin';

    /** Always send the full URL. The browser default, and a leak. */
    case UnsafeUrl = 'unsafe-url';

    /**
     * The policy name as the header carries it.
     *
     * A one-line `render()` so the case can be handed to {@link \Phpanta\Http\Header} directly.
     * Same arrangement as {@link ContentTypeOptions}: an enum whose backing value *is* the whole
     * header value has nothing to compose, so the interface costs it one method.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->value;
    }
}
