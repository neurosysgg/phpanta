<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Exception\SecurityPolicyException;
use Uri\WhatWg\Url;

/**
 * The Origin class. A scheme, a host and a port — `https://example.org`, `http://localhost:8080` —
 * and nothing else.
 *
 * What a cross-origin request says it comes from, what a site lists as allowed to read its
 * responses, and what `Access-Control-Allow-Origin` names back — the three meet in the
 * {@link \Phpanta\Service\Layer\Cors} layer, and they are compared as origins rather than as
 * strings.
 *
 * **Exact, or not an origin.** The value must already be written the way the WHATWG parser writes
 * one back — lower-case scheme and host, no default port, no path, no trailing slash, no user, no
 * query — so a listed origin can never differ from the one a browser sends by a spelling.
 * `https://Example.org/` is refused where a site writes it, and is no origin at all where a request
 * sends it.
 */
final readonly class Origin implements HeaderValue
{
    /**
     * @param string $origin Already checked to be one, exactly.
     */
    private function __construct(private string $origin) {}

    /**
     * The origin $value names, written exactly as one.
     *
     * @param string $value
     * @return self
     * @throws SecurityPolicyException if $value is not exactly an `http` or `https` origin.
     */
    public static function of(string $value): self
    {
        return self::tryFrom($value) ?? throw new SecurityPolicyException(sprintf(
            "'%s' is not an origin written exactly: a scheme, a host and a port only — like "
            . "'https://example.org' — in lower case, with no path, no trailing slash and no default port.",
            $value,
        ));
    }

    /**
     * The origin $value names, or null where it names none — what a request's `Origin` header is
     * read as, since a browser sends `null` and a client may send anything.
     *
     * @param string $value
     * @return self|null
     */
    public static function tryFrom(string $value): ?self
    {
        $url = Url::parse($value);

        if ($url === null || !in_array($url->getScheme(), ['http', 'https'], true)) {
            return null;
        }

        $port      = $url->getPort();
        $canonical = $url->getScheme() . '://' . $url->getAsciiHost() . ($port === null ? '' : ':' . $port);

        return $canonical === $value ? new self($canonical) : null;
    }

    /**
     * Whether $other is this same origin.
     *
     * @param Origin $other
     * @return bool
     */
    public function equals(Origin $other): bool
    {
        return $this->origin === $other->origin;
    }

    /**
     * @return string
     */
    public function render(): string
    {
        return $this->origin;
    }
}
