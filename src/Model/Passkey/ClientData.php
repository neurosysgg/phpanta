<?php

declare(strict_types=1);

namespace Phpanta\Model\Passkey;

use JsonException;
use stdClass;

/**
 * The ClientData class. What a browser says about the ceremony it ran: which one, for which challenge,
 * on which origin.
 *
 * The browser writes it and the authenticator signs a digest of it, so what it says is the browser's
 * word, bound by the signature. Everything here is only read; whether it is what the admin asked for
 * is {@link \Phpanta\Service\Passkey\PasskeyVerifier}'s question.
 */
final readonly class ClientData
{
    /** A client data object is flat, and a token binding is its one nested member. */
    private const int MAX_DEPTH = 4;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $type        The ceremony, as written.
     * @param string $challenge   The challenge, base64url, as written.
     * @param string $origin      The page's origin, as written.
     * @param bool   $crossOrigin Whether it ran in another origin's frame.
     */
    private function __construct(
        public string $type,
        public string $challenge,
        public string $origin,
        public bool   $crossOrigin,
    ) {}

    /**
     * What $json says, or null where it is not a client data object.
     *
     * @param string $json The bytes the browser handed over — exactly those, since a digest of them is
     *                     what was signed.
     * @return self|null
     */
    public static function parse(string $json): ?self
    {
        try {
            $data = json_decode($json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!$data instanceof stdClass) {
            return null;
        }

        $type        = $data->{ClientDataKey::Type->value} ?? null;
        $challenge   = $data->{ClientDataKey::Challenge->value} ?? null;
        $origin      = $data->{ClientDataKey::Origin->value} ?? null;
        $crossOrigin = $data->{ClientDataKey::CrossOrigin->value} ?? false;

        if (!is_string($type) || !is_string($challenge) || !is_string($origin) || !is_bool($crossOrigin)) {
            return null;
        }

        return new self($type, $challenge, $origin, $crossOrigin);
    }
}
