<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

use Phpanta\Exception\SecurityPolicyException;
use Phpanta\Http\HeaderValue;

/**
 * The StrictTransportSecurity class. A `Strict-Transport-Security` header, built from its parts.
 *
 * The one security header that is about the connection rather than the document. It tells the
 * browser to refuse plaintext to this host for the next {@link self::$maxAge} seconds, so a visitor
 * who types the bare domain or follows an old `http://` link never sends a request the network can
 * read. The redirect in `public/.htaccess` is the other half: that one fixes the *current* request,
 * this one stops there being a next one.
 *
 * It matters more here than on a site with nothing to log into. Both gates are HTTP Basic — and the
 * pre-launch one runs on *every* request, not just an admin route — and Basic is base64, which is a
 * transport encoding and not encryption. Sent in plaintext the credentials are simply legible to
 * anyone on the path, and a redirect cannot help: by the time it is received the `Authorization`
 * header has already crossed the wire.
 *
 * A class rather than an enum because the value carries a number. Same shape as
 * {@link ContentSecurityPolicy} and {@link PermissionsPolicy}: typed parts and one `render()`.
 *
 * **There is deliberately no `preload` flag.** Preloading ships the host in the browser's own
 * binary, where nothing this server sends can correct it — removal is a request to a list Google
 * maintains and then a wait for browsers to ship the change. That is a decision to make on purpose
 * with a working HTTPS estate behind it, not a boolean to pass here on the way past.
 */
final readonly class StrictTransportSecurity implements HeaderValue
{
    /** One year — the value the preload guidance asks for, and the right end state. */
    public const int ONE_YEAR = 31_536_000;

    /**
     * One day. What to ship first if there is any doubt about the estate.
     *
     * Worth knowing why this is here: a browser that has seen this header will not speak plaintext
     * to the host again until the max-age expires, and the only thing that can shorten it is a
     * smaller value delivered *over HTTPS*. So a host that then loses its certificate is not
     * serving a broken page — it is unreachable, for as long as the number said. Ship a day, check
     * every name that resolves here, then raise it to {@link self::ONE_YEAR}.
     */
    public const int ONE_DAY = 86_400;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int  $maxAge            How long the browser should refuse plaintext, in seconds.
     * @param bool $includeSubDomains Apply the same rule to every subdomain. On by default: a
     *                                subdomain still reachable over plaintext is somewhere to
     *                                inject content that the parent origin's own policy never
     *                                sees. Turn it off only for an estate that genuinely has a
     *                                name which cannot serve HTTPS.
     *
     * @throws SecurityPolicyException if the max-age is negative — `max-age=0` is the legitimate
     *                                 way to switch the policy off, but a negative one is a header
     *                                 the browser discards, which reads as protection that is not
     *                                 there.
     */
    public function __construct(
        private int  $maxAge            = self::ONE_YEAR,
        private bool $includeSubDomains = true,
    ) {
        if ($this->maxAge < 0) {
            throw new SecurityPolicyException(sprintf(
                'StrictTransportSecurity::$maxAge must not be negative, got %d. '
                . 'Use 0 to switch the policy off.',
                $this->maxAge,
            ));
        }
    }

    /**
     * Returns the header value: `max-age=31536000; includeSubDomains`.
     *
     * @return string
     */
    public function render(): string
    {
        return 'max-age=' . $this->maxAge . ($this->includeSubDomains ? '; includeSubDomains' : '');
    }
}
