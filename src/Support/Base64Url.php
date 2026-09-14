<?php

declare(strict_types=1);

namespace Phpanta\Support;

/**
 * The Base64Url class. RFC 4648 §5's base64 — `-` and `_` for `+` and `/`, and no padding — which is
 * what a cookie, a URL and WebAuthn all carry bytes as.
 *
 * One spelling of it rather than a `strtr()` wherever bytes cross into text: a sealed session, a
 * passkey's credential id and every buffer a browser's ceremony hands back are all written this way,
 * and an encoder and a decoder that each spelled the alphabet for themselves would be two
 * alphabets free to disagree.
 *
 * **Decoding refuses rather than guesses.** A character outside the alphabet, or a length no encoding
 * produces, is null — never the bytes a lenient decoder would make of it.
 */
final readonly class Base64Url
{
    /** The alphabet, and nothing else — no padding, no whitespace. */
    private const string ALPHABET = '/\A[A-Za-z0-9_-]*\z/';

    /**
     * $bytes as text.
     *
     * @param string $bytes
     * @return string
     */
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * The bytes $text is, or null where it is not base64url.
     *
     * @param string $text
     * @return string|null
     */
    public static function decode(string $text): ?string
    {
        // One character past a whole group encodes six bits, which is not a byte: no encoder writes it.
        if (preg_match(self::ALPHABET, $text) !== 1 || strlen($text) % 4 === 1) {
            return null;
        }

        $padded = str_pad(strtr($text, '-_', '+/'), (int) ceil(strlen($text) / 4) * 4, '=');
        $bytes  = base64_decode($padded, true);

        return $bytes === false ? null : $bytes;
    }
}
