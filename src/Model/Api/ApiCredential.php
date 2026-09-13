<?php

declare(strict_types=1);

namespace Phpanta\Model\Api;

use Phpanta\Exception\ApiException;

/**
 * The ApiCredential class. What rides in the `Authorization` header of every signed API request: a
 * manifest, and the signature over it.
 *
 * ```
 * 0     4    manifest length   u32 big-endian
 * 4     n    manifest JSON     UTF-8
 * 4+n   …    signature         ECDSA DER, the remainder
 * ```
 *
 * base64 of that, after `NS1 `. base64's alphabet is exactly RFC 9110's `token68`, so the whole
 * thing is one auth-param and nothing between here and the client can read it as several.
 *
 * **One length prefix, and no magic.** The second segment is simply the rest, and the scheme token
 * {@link \Phpanta\Http\AuthScheme::NS1} is the magic — it carries the format's version digit,
 * which is what a magic is for. The payload the manifest vouches for is not inside this at all: it
 * is the request body, which is what makes a bodyless GET signable on the same terms as a push.
 *
 * **Why a header.** `Authorization` is a {@link \Phpanta\Http\ServerVariable}, not a
 * {@link \Phpanta\Http\RequestHeader}, so it has no TypeScript mirror and puts nothing in the
 * browser's bundle. A header is the part of a request most likely to be rewritten in transit,
 * which is exactly why `public/.htaccess` puts this one back with `E=HTTP_AUTHORIZATION` and why
 * {@link \Phpanta\Http\Request::authorization()} reads both spellings. Both auth gates already
 * depend on this header surviving Strato, which is the strongest evidence available that it does.
 * See docs/history/api.md.
 *
 * **Nothing here is trusted.** This establishes only that the bytes are shaped like a credential;
 * whether they are *ours* is {@link \Phpanta\Service\ApiGate}'s question, and it cannot be asked
 * until the framing has been read — which is why the framing is read this carefully. Every length
 * is bounded against what is actually present before it is used as an offset, and every failure is
 * an exception the gate turns into the same silence as every other.
 */
final readonly class ApiCredential
{
    /** The length prefix's width. */
    private const int LENGTH_WIDTH = 4;

    /**
     * The most a manifest may claim to be.
     *
     * **This is arithmetic against a real limit, unlike the body framing's 8192.** Apache's
     * `LimitRequestFieldSize` bounds the whole field line at 8190 bytes. `Authorization: NS1 `
     * is 19 of them, base64 is 4 bytes out for every 3 in, and the frame carries 4 bytes of
     * length and up to {@link self::MAX_SIGNATURE} of signature — so this cap costs
     * `19 + 4 * ceil((4 + 2048 + 256) / 3)` = 3099 bytes, leaving 62% of the line spare. A real
     * manifest is about 190 bytes, so the headroom is a factor of ten and "needing more than this"
     * is the signal that a field belongs in the body rather than in the credential.
     */
    private const int MAX_MANIFEST = 2048;

    /** The most a signature may claim to be. An ECDSA P-256 DER signature is 70-72 bytes. */
    private const int MAX_SIGNATURE = 256;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $manifest The manifest's raw bytes — what the signature must be checked
     *                         against, byte for byte, rather than a re-encoding of the parsed form.
     * @param string $signature The DER signature, raw.
     */
    private function __construct(
        public string $manifest,
        public string $signature,
    ) {}

    /**
     * Reads the credential out of the `Authorization` header's parameters.
     *
     * **The token, not the whole header.** Which scheme a header is in is a question about the
     * header, and {@link \Phpanta\Http\Request::credential()} has already answered it by the
     * time anything gets here — asking again would be the same check in two files, with this one
     * having to be told the scheme it was already selected by.
     *
     * @param string $token The base64 after `NS1 `.
     * @return self
     *
     * @throws ApiException if the token is not base64, or declares a length that does not fit
     *                      inside it.
     */
    public static function parse(string $token): self
    {
        // strict: true, so base64 that is not base64 comes back false rather than being silently
        // repaired into some other credential. The check is not optional in the way it looks:
        // under strict_types a false reaching strlen() below is an uncaught TypeError, which is a
        // 500 — and a 500 on /api and a 405 on an address that does not exist is the whole
        // property gone. Same trap Request::normalisePath() records parse_url() setting.
        $blob = base64_decode($token, true);

        if ($blob === false) {
            throw new ApiException('the API credential is not base64');
        }

        $length = strlen($blob);

        if ($length < self::LENGTH_WIDTH) {
            throw new ApiException('the API credential ends before its manifest length');
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($blob, 0, self::LENGTH_WIDTH));
        $size     = $unpacked[1];

        if ($size > self::MAX_MANIFEST) {
            throw new ApiException(sprintf(
                'the API credential declares a %d-byte manifest, over the %d-byte limit',
                $size,
                self::MAX_MANIFEST,
            ));
        }

        if (self::LENGTH_WIDTH + $size > $length) {
            throw new ApiException(sprintf(
                'the API credential declares a %d-byte manifest but only %d bytes follow',
                $size,
                $length - self::LENGTH_WIDTH,
            ));
        }

        $signature = substr($blob, self::LENGTH_WIDTH + $size);

        // Bounded like the manifest, and for the same reason: it is attacker-supplied and about to
        // be handed to openssl. A P-256 signature is 72 bytes at the outside, so anything near this
        // is not one.
        if (strlen($signature) > self::MAX_SIGNATURE) {
            throw new ApiException(sprintf(
                'the API credential carries a %d-byte signature, over the %d-byte limit',
                strlen($signature),
                self::MAX_SIGNATURE,
            ));
        }

        return new self(substr($blob, self::LENGTH_WIDTH, $size), $signature);
    }
}
