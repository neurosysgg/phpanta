<?php

declare(strict_types=1);

namespace Phpanta\Model\Passkey;

/**
 * The AuthenticatorData class. What the authenticator itself says, inside the bytes it signs: for which
 * relying party, whether a person was there, and how many times it has signed.
 *
 * W3C WebAuthn §6.1: thirty-two bytes of `sha256(rpId)`, one byte of flags, four bytes of signature
 * count, big-endian — and then, for a registration, the credential. Only the first thirty-seven bytes
 * are read here; the rest is covered by the signature and is never needed, since the key a
 * registration makes is taken from the browser's own `getPublicKey()` rather than decoded out of CBOR.
 */
final readonly class AuthenticatorData
{
    /** How long the relying party's hash is. */
    private const int HASH_BYTES = 32;

    /** The hash, the flags and the count: the least any authenticator data is. */
    private const int LEAST = 37;

    /** UP: a person touched it. */
    private const int USER_PRESENT = 0x01;

    /** UV: and was verified — a fingerprint, a face, a PIN. */
    private const int USER_VERIFIED = 0x04;

    /** AT: a credential follows — what a registration carries. */
    private const int ATTESTED = 0x40;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $rpIdHash The relying party's hash, raw.
     * @param int    $flags    The flags byte.
     * @param int    $count    The signature count; 0 for an authenticator that does not count.
     */
    private function __construct(
        public string $rpIdHash,
        private int   $flags,
        public int    $count,
    ) {}

    /**
     * What $bytes say, or null where they are too short to be authenticator data.
     *
     * @param string $bytes
     * @return self|null
     */
    public static function parse(string $bytes): ?self
    {
        if (strlen($bytes) < self::LEAST) {
            return null;
        }

        // Four bytes, big-endian, read a byte at a time: there is no unsigned 32-bit number PHP
        // cannot hold, and nothing here to go wrong the way a format string can.
        $count = 0;

        for ($at = self::HASH_BYTES + 1; $at < self::LEAST; $at++) {
            $count = ($count << 8) | ord($bytes[$at]);
        }

        return new self(substr($bytes, 0, self::HASH_BYTES), ord($bytes[self::HASH_BYTES]), $count);
    }

    /**
     * @return bool
     */
    public function userPresent(): bool
    {
        return ($this->flags & self::USER_PRESENT) !== 0;
    }

    /**
     * @return bool
     */
    public function userVerified(): bool
    {
        return ($this->flags & self::USER_VERIFIED) !== 0;
    }

    /**
     * @return bool
     */
    public function attested(): bool
    {
        return ($this->flags & self::ATTESTED) !== 0;
    }
}
