<?php

declare(strict_types=1);

namespace Phpanta\Service\Passkey;

use NoDiscard;
use Phpanta\Http\Origin;
use Phpanta\Model\Passkey\AuthenticatorData;
use Phpanta\Model\Passkey\CeremonyType;
use Phpanta\Model\Passkey\ClientData;
use Phpanta\Model\Passkey\Passkey;
use Phpanta\Support\PublicKey;

/**
 * The PasskeyVerifier class. Whether what a browser handed back is the ceremony the admin asked for,
 * on this origin, answered by a person — and, for an unlock or a write, signed by an enrolled key.
 *
 * **ES256 through the one key reader there is.** WebAuthn's default algorithm is ECDSA over P-256 with
 * SHA-256, and an assertion's signature is DER over `authenticatorData ‖ sha256(clientDataJSON)` —
 * which is exactly what {@link \Phpanta\Support\PublicKey::verifies()} checks for the update key. So a
 * passkey needs no cryptography the framework did not already have, and no key of another kind is ever
 * accepted, because that class accepts P-256 and nothing else.
 *
 * Every check is made every time, in no order an answer could steer: the ceremony's type, the
 * challenge, the origin and that it did not run in another origin's frame, the relying party's hash,
 * that a person was present and verified, the signature, and a signature count that rises.
 */
final readonly class PasskeyVerifier
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Origin $origin The origin the admin is served on; its host is the relying party.
     */
    public function __construct(private Origin $origin) {}

    /**
     * The signature count $passkey reported, if this is its assertion over $challenge — or null.
     *
     * **A count that has been counting must rise.** An authenticator that counts signs with a number
     * one higher each time, so the same number twice is two authenticators answering for one key — a
     * cloned key — and is refused. One that does not count reports zero, and that is accepted, since
     * most passkeys synced between devices do not count at all.
     *
     * @param Passkey $passkey
     * @param string  $authenticatorData Raw.
     * @param string  $clientData        The `clientDataJSON` bytes, raw.
     * @param string  $signature         DER, raw.
     * @param string  $challenge         The challenge the page handed out, base64url.
     * @return int|null
     */
    #[NoDiscard('asserts() is the whole of an unlock\'s decision; a result that goes nowhere let nobody in')]
    public function asserts(
        Passkey $passkey,
        string $authenticatorData,
        string $clientData,
        string $signature,
        string $challenge,
    ): ?int {
        $authenticator = AuthenticatorData::parse($authenticatorData);
        $key           = $passkey->publicKey();

        if (
            $authenticator === null
            || $key === null
            || !$this->answers(ClientData::parse($clientData), CeremonyType::Get, $challenge)
            || !$this->ours($authenticator)
            || (($authenticator->count !== 0 || $passkey->count !== 0) && $authenticator->count <= $passkey->count)
        ) {
            return null;
        }

        return $key->verifies($authenticatorData . hash(PublicKey::DIGEST, $clientData, true), $signature)
            ? $authenticator->count
            : null;
    }

    /**
     * Whether this is a registration over $challenge of the P-256 key $key.
     *
     * The key is the one the browser's own `getPublicKey()` handed over rather than one decoded out of
     * the attestation, and nothing about the device is attested: a registration only ever becomes an
     * enrolment code, and what trusts it is the signed `access v1 enrol` that adds it.
     *
     * @param string $authenticatorData Raw.
     * @param string $clientData        Raw.
     * @param string $key               The public key, SPKI DER, raw.
     * @param string $challenge         Base64url.
     * @return bool
     */
    #[NoDiscard('registers() is a registration\'s whole decision; a result that goes nowhere decided nothing')]
    public function registers(string $authenticatorData, string $clientData, string $key, string $challenge): bool
    {
        $authenticator = AuthenticatorData::parse($authenticatorData);

        return $authenticator !== null
            && $authenticator->attested()
            && $this->ours($authenticator)
            && $this->answers(ClientData::parse($clientData), CeremonyType::Create, $challenge)
            && new Passkey('', '', $key)->publicKey() !== null;
    }

    /**
     * Whether $client says it ran $type over $challenge, on this origin, in no one else's frame.
     *
     * @param ClientData|null $client
     * @param CeremonyType    $type
     * @param string          $challenge
     * @return bool
     */
    private function answers(?ClientData $client, CeremonyType $type, string $challenge): bool
    {
        return $client !== null
            && $client->type === $type->value
            && !$client->crossOrigin
            && $client->origin === $this->origin->render()
            && hash_equals($challenge, $client->challenge);
    }

    /**
     * Whether $authenticator answered this relying party, with a person present and verified.
     *
     * @param AuthenticatorData $authenticator
     * @return bool
     */
    private function ours(AuthenticatorData $authenticator): bool
    {
        return hash_equals(hash(PublicKey::DIGEST, $this->origin->host(), true), $authenticator->rpIdHash)
            && $authenticator->userPresent()
            && $authenticator->userVerified();
    }
}
