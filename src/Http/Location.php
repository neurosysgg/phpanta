<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Exception\SecurityPolicyException;
use Phpanta\Support\BareString;
use Phpanta\View\Html\Element;

/**
 * The Location class. Where a redirect points.
 *
 * The one header value the framework sends that carries a URL, and therefore the one where the
 * type buys a check rather than only a grammar: {@link self::verify()} refuses anything that is not
 * an absolute `https://` address or a path on this site. That is narrower than the spec allows, and
 * narrower on purpose, for the same reason an external link is narrower than an `href` in general.
 * A redirect goes to another host, off-origin and over TLS, or back to a page of this site — after
 * a language switch, say; anything else is a mistake rather than a case to support.
 *
 * A path is put to {@link Element::staysOnThisOrigin()} rather than trusted for its leading slash:
 * `//evil.example` starts with one, and is another host.
 *
 * It is the counterpart to {@link \Phpanta\View\Html\Element}'s scheme check, one layer along:
 * that one governs a URL the browser is asked to *render*, this one a URL it is told to *follow*.
 */
#[BareString(
    '#^https://[^\s/]+(?:[/?\#]\S*)?\z#i',
    'the same pattern a site may keep for the https URLs its own data carries, and deliberately a '
    . 'second copy of it. The two are checks on two different kinds of address — a header the '
    . 'framework emits, and data a site reads — and they throw different exceptions for that '
    . 'reason. Sharing one constant would mean a change made for a redirect silently changed what '
    . 'a site\'s data may hold.',
)]
final readonly class Location implements HeaderValue
{
    /**
     * An absolute `https://` URL: a host, then optionally a path, query or fragment.
     *
     * `\S` throughout so no whitespace survives anywhere, and `\z` rather than `$` because `$` also
     * matches before a trailing newline — the two details every validating pattern here keeps, for
     * the same two reasons. A newline in particular is what would turn a
     * redirect into header injection if PHP's own `header()` did not already refuse one.
     */
    private const string URL_PATTERN = '#^https://[^\s/]+(?:[/?\#]\S*)?\z#i';

    /**
     * A path, with no whitespace in it — the same `\S` and `\z` as above, for the same reasons.
     * Whether it stays on this site is the WHATWG parser's to say, not this pattern's.
     */
    private const string PATH_PATTERN = '#^/\S*\z#';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $url The address to redirect to: absolute `https://`, or a path on this site.
     *
     * @throws SecurityPolicyException if it is neither.
     */
    public function __construct(private string $url)
    {
        $this->verify();
    }

    /**
     * Returns the header value: the URL, as given.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->url;
    }

    /**
     *
     * @return void
     * @throws SecurityPolicyException
     */
    private function verify(): void
    {
        if (preg_match(self::URL_PATTERN, $this->url) === 1) {
            return;
        }

        if (preg_match(self::PATH_PATTERN, $this->url) === 1 && Element::staysOnThisOrigin($this->url)) {
            return;
        }

        throw new SecurityPolicyException(sprintf(
            "Location must be an absolute https:// URL or a path on this site, got '%s'.",
            $this->url,
        ));
    }
}
