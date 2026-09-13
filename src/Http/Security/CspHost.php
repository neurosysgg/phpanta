<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

use Phpanta\Exception\SecurityPolicyException;

/**
 * The CspHost class. A single origin allowed by a CSP directive.
 *
 * The shape is validated where it is written, so a paste that carries a path or a trailing slash
 * throws on boot instead of producing a directive the browser drops on the floor. A CSP host
 * source is an *origin* — scheme, host and optional port — and nothing else;
 * `https://cdn.example.test/files/share` matches nothing.
 */
final readonly class CspHost implements CspSource
{
    /**
     * Scheme, optional `*.` subdomain wildcard, dotted host, optional port. No path, no query,
     * no fragment, no trailing slash.
     */
    private const string ORIGIN_PATTERN = '#^https?://(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(:\d{1,5})?\z#i';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $origin An origin such as 'https://cdn.example.test'.
     *
     * @throws SecurityPolicyException if $origin is not a bare origin.
     */
    public function __construct(public string $origin)
    {
        $this->verify();
    }

    /**
     * @return string
     */
    public function source(): string
    {
        return $this->origin;
    }

    /**
     *
     * @return void
     * @throws SecurityPolicyException
     */
    private function verify(): void
    {
        if (preg_match(self::ORIGIN_PATTERN, $this->origin) !== 1) {
            throw new SecurityPolicyException(sprintf(
                "CspHost::origin must be a bare origin like 'https://example.com', got '%s'. "
                . 'A CSP host source carries no path, query or trailing slash.',
                $this->origin,
            ));
        }
    }
}
