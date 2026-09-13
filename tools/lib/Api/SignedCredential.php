<?php

declare(strict_types=1);

namespace Phpanta\Tool\Api;

use Phpanta\Http\AuthScheme;
use Phpanta\Http\HeaderValue;
use Phpanta\Model\Api\ApiCredential;

/**
 * The SignedCredential class. The writing half of {@link ApiCredential}, which is the reading half.
 *
 * The layout is stated once — there — and this builds to that description; nothing here restates
 * the offsets. It is a {@link HeaderValue} so that {@link \Phpanta\Http\Header} formats it exactly
 * as every other header value on either side of the wire, which is what keeps `NS1 ` from being a
 * prefix somebody concatenates at a call site.
 *
 * **The scheme token is {@link AuthScheme::NS1}, the same case the server matches on.** Both
 * autoloaders are registered for a command, so the tooling reads the site's own vocabulary rather
 * than a copy of it — which matters more here than almost anywhere else: the two sides of an
 * authentication handshake spelling one token in two files is the failure `AuthScheme` was written
 * to end, and a signed push would fail closed and in silence, looking exactly like a bad key.
 */
final readonly class SignedCredential implements HeaderValue
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $manifest The manifest's raw bytes.
     * @param string $signature The DER signature over exactly those bytes.
     */
    public function __construct(private string $manifest, private string $signature) {}

    /**
     * The header value: `NS1 <base64>`.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->scheme() . ' ' . base64_encode(
            pack('N', strlen($this->manifest)) . $this->manifest . $this->signature,
        );
    }

    /**
     * The scheme token, from the site's own enum.
     *
     * @return string
     */
    private function scheme(): string
    {
        return AuthScheme::NS1->value;
    }
}
