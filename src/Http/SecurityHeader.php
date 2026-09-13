<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The SecurityHeader enum. The response headers {@link SecurityHeaders} sends.
 *
 * Backed by the literal header name, so the wire format lives here rather than in a string
 * scattered through a `header()` call.
 *
 * Deliberately exhaustive: {@link SecurityHeaders::headers()} sends exactly these cases and a test
 * asserts it, so a case added without being sent — or a header sent without a case — fails. Every
 * other response header is a {@link ResponseHeader}; see {@link HeaderName}.
 */
enum SecurityHeader: string implements HeaderName
{
    /**
     * Refuses plaintext to this host for as long as it says.
     *
     * First because it is the only one about the connection rather than the document: the others
     * all constrain a page that has already arrived. See {@link Security\StrictTransportSecurity}
     * for why a site whose only secret is a Basic Auth password still needs it.
     */
    case StrictTransportSecurity = 'Strict-Transport-Security';

    /** Restricts where the page may load anything from. */
    case ContentSecurityPolicy = 'Content-Security-Policy';

    /** Controls how much of the current URL is sent as `Referer` on an outgoing request. */
    case ReferrerPolicy = 'Referrer-Policy';

    /** Stops the browser second-guessing a declared Content-Type. */
    case ContentTypeOptions = 'X-Content-Type-Options';

    /** Switches off browser features the site never uses. */
    case PermissionsPolicy = 'Permissions-Policy';

    /**
     * Whether a window this site opens, or that opens it, may keep a handle on the other across
     * origins — see {@link Security\CrossOriginOpenerPolicy}.
     */
    case CrossOriginOpenerPolicy = 'Cross-Origin-Opener-Policy';

    /**
     * Which other origins may load this response as a resource — see
     * {@link Security\CrossOriginResourcePolicy}.
     */
    case CrossOriginResourcePolicy = 'Cross-Origin-Resource-Policy';

    /**
     * @return string
     */
    public function headerName(): string
    {
        return $this->value;
    }
}
