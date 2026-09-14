<?php

declare(strict_types=1);

namespace Phpanta\Model\Passkey;

use JsonSerializable;
use Phpanta\Exception\UpdateException;
use Phpanta\Support\Base64Url;
use Phpanta\Support\PublicKey;
use stdClass;

/**
 * The Passkey class. One device's key to the admin: its credential id, a name, its public key, and the
 * last signature count it reported.
 *
 * **The public half only**, like the update key: the private half never leaves the authenticator that
 * made it, so a full compromise of the host yields keys that open nothing. The key is kept as the SPKI
 * DER the browser handed over, and read through {@link PublicKey}, which accepts P-256 and nothing
 * else — so a key of any other kind is simply no key, and fails every comparison.
 */
final readonly class Passkey implements JsonSerializable
{
    /** How much of the key's digest a fingerprint shows: enough to tell two keys apart by eye. */
    private const int FINGERPRINT = 16;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $id    The credential id, base64url.
     * @param string $name  What it was called when it was enrolled.
     * @param string $key   The public key, as SPKI DER, raw.
     * @param int    $count The last signature count it reported.
     * @param string $added When it was enrolled, ISO 8601.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $key,
        public int    $count = 0,
        public string $added = '',
    ) {}

    /**
     * The passkey $data describes, or null where it does not describe one.
     *
     * @param mixed $data One decoded member of the store.
     * @return self|null
     */
    public static function fromData(mixed $data): ?self
    {
        if (!$data instanceof stdClass) {
            return null;
        }

        $id    = $data->{PasskeyField::Id->value} ?? null;
        $name  = $data->{PasskeyField::Name->value} ?? null;
        $key   = $data->{PasskeyField::Key->value} ?? null;
        $count = $data->{PasskeyField::Count->value} ?? null;
        $added = $data->{PasskeyField::Added->value} ?? null;
        $der   = is_string($key) ? Base64Url::decode($key) : null;

        if (!is_string($id) || !is_string($name) || $der === null || !is_int($count) || !is_string($added)) {
            return null;
        }

        return new self($id, $name, $der, $count, $added);
    }

    /**
     * This passkey, having reported $count.
     *
     * @param int $count
     * @return self
     */
    public function counted(int $count): self
    {
        return new self($this->id, $this->name, $this->key, $count, $this->added);
    }

    /**
     * The key, read — or null where it is not a P-256 public key, which is no key at all.
     *
     * @return PublicKey|null
     */
    public function publicKey(): ?PublicKey
    {
        try {
            return PublicKey::fromPem(self::pem($this->key));
        } catch (UpdateException) {
            return null;
        }
    }

    /**
     * The key's fingerprint — what the page that registered it showed, and what `access v1 passkeys`
     * lists, so a person can match the two.
     *
     * @return string
     */
    public function fingerprint(): string
    {
        return self::fingerprintOf($this->key);
    }

    /**
     * The device as a person names it: what it is called, then its fingerprint.
     *
     * @return string
     */
    public function label(): string
    {
        return sprintf('%s  %s', $this->name, $this->fingerprint());
    }

    /**
     * The fingerprint of $key, as SPKI DER: the start of its SHA-256, in groups of four.
     *
     * @param string $key
     * @return string
     */
    public static function fingerprintOf(string $key): string
    {
        return implode(' ', str_split(substr(hash(PublicKey::DIGEST, $key), 0, self::FINGERPRINT), 4));
    }

    /**
     * The passkey as the store keeps it.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return [
            PasskeyField::Id->value    => $this->id,
            PasskeyField::Name->value  => $this->name,
            PasskeyField::Key->value   => Base64Url::encode($this->key),
            PasskeyField::Count->value => $this->count,
            PasskeyField::Added->value => $this->added,
        ];
    }

    /**
     * $key, as the PEM block {@link PublicKey} reads.
     *
     * @param string $key SPKI DER.
     * @return string
     */
    private static function pem(string $key): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($key), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}
