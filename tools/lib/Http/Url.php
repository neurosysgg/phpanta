<?php

declare(strict_types=1);

namespace Phpanta\Tool\Http;

use InvalidArgumentException;
use Uri\Rfc3986\Uri;

/**
 * The Url class. An absolute `https://` address this tooling is about to send something to.
 *
 * The site checks every address it emits — {@link \Phpanta\Http\Location} refuses a redirect target
 * that is not absolute https, {@link \Phpanta\Http\Security\CspHost} refuses anything but a bare
 * origin, {@link \Phpanta\View\Html\Element} refuses a scheme its allowlist does not name. The one
 * address with nothing looking at it was this one: the target of a request carrying a client secret
 * and a rotating refresh token, passed to {@link Request} as a `string` and handed to
 * {@link CurlTransport} as whatever that string happened to be. (Named through the class rather than
 * through the function it calls, because `test/basic_test.sh` asserts that one file under
 * `tools/lib/` reaches the extension and greps for the prefix to do it — a check that cannot tell
 * prose from a call site, and should not have to. Same reason `Terminal` names a tag through its
 * enum.)
 *
 * **It is not `Location`, though it wants the same thing of a URL.** That class is a
 * {@link \Phpanta\Http\HeaderValue} — a header the *site* sends on a response — and it lives under
 * `src/`, which `deploy.sh` uploads and `phpunit.xml.dist` counts as coverage source. A target a
 * *command* aims a request at is a different fact that happens to have the same shape, and it
 * belongs on this side of the wall. See `tools/autoload.php` for why that wall exists.
 *
 * **Parsed rather than pattern-matched.** `Location` predates this and uses a regular expression;
 * PHP 8.5 ships the parser, `\Phpanta\Http\Request::path()` already asks it questions, and
 * `Element::staysOnThisOrigin()` records what a list of the spellings that occurred to us cost the
 * last time one was written. `Uri\Rfc3986\Uri::parse()` answers null for a target it cannot read,
 * which is the same shape of answer as a scheme that is not `https`.
 *
 * `InvalidArgumentException` rather than a named one, because that is exactly what this is: every
 * address here is built from a constant in `Endpoint`, so a refusal
 * is a mistake in this repository's own source rather than a condition a run can encounter. Same
 * reasoning as {@link \Phpanta\Support\TypedItems}'s plain `TypeError`.
 */
final readonly class Url
{
    /** The only scheme anything here sends to, and the one a credential may ride on. */
    private const string SCHEME = 'https';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $url The absolute address.
     * @throws InvalidArgumentException if it is not an absolute https:// URL with a host.
     */
    public function __construct(private string $url)
    {
        $parsed = Uri::parse($url);

        if ($parsed?->getScheme() !== self::SCHEME || ($parsed->getHost() ?? '') === '') {
            throw new InvalidArgumentException(sprintf(
                "A request target must be an absolute https:// URL with a host, got '%s'.",
                $url,
            ));
        }
    }

    /**
     * An origin — `https://` and a host, a port if it has one, and nothing after it.
     *
     * The shape `--url` takes. A path there would be signed as `/api/…` and sent to `/sub/api/…`,
     * and come back as the refusal that says nothing about why; a user part would be a credential in
     * an address. Both are refused where they are typed. The host is lower-cased and a trailing slash
     * dropped, so two spellings of one origin compare equal.
     *
     * @param string $origin
     * @return self
     * @throws InvalidArgumentException if it is not a bare https origin.
     */
    public static function origin(string $origin): self
    {
        $parsed = Uri::parse($origin);

        $bare = $parsed !== null
            && $parsed->getScheme() === self::SCHEME
            && ($parsed->getHost() ?? '') !== ''
            && $parsed->getUserInfo() === null
            && ($parsed->getPath() === '' || $parsed->getPath() === '/')
            && $parsed->getQuery() === null
            && $parsed->getFragment() === null;

        if (!$bare) {
            throw new InvalidArgumentException(sprintf(
                "An origin is https:// and a host, with no path, query, fragment or user part — got '%s'.",
                $origin,
            ));
        }

        $port = $parsed->getPort();
        $host = strtolower((string) $parsed->getHost());

        return new self(self::SCHEME . '://' . $host . ($port === null ? '' : ':' . $port));
    }

    /**
     * The host, and `:port` where the address names one.
     *
     * @return string
     */
    public function authority(): string
    {
        $parsed = Uri::parse($this->url);
        $port   = $parsed?->getPort();

        return strtolower((string) $parsed?->getHost()) . ($port === null ? '' : ':' . $port);
    }

    /**
     * The address as it goes to the transport.
     *
     * `render()` rather than `__toString()`, the name every other wire form in this codebase uses
     * and for the reason {@link \Phpanta\Http\HeaderValue} gives: a `Stringable` would let this be
     * concatenated into somewhere it was never checked for.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->url;
    }
}
