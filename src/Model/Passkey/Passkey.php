<?php

declare(strict_types=1);

namespace Phpanta\Model\Passkey;

use JsonSerializable;
use Phpanta\Exception\UpdateException;
use Phpanta\Support\Base64Url;
use Phpanta\Support\PublicKey;
use stdClass;

/**
 * The Passkey class. One device's key to the admin: its credential id, a name, its public key, the last
 * signature count it reported, and when it last unlocked and locked the admin.
 *
 * **The public half only**, like the update key: the private half never leaves the authenticator that
 * made it, so a full compromise of the host yields keys that open nothing. The key is kept as the SPKI
 * DER the browser handed over, and read through {@link PublicKey}, which accepts P-256 and nothing
 * else — so a key of any other kind is simply no key, and fails every comparison.
 *
 * **What a sealed session cannot say, the store does.** A session is the visitor's own cookie, so the
 * server can neither take one back nor tell a copy from the original. The two times kept here are the
 * server's half: {@link self::$unlocked}, so an unlock over a challenge no newer than the last one is a
 * replay and opens nothing; and {@link self::$locked}, so a session this passkey unlocked before the
 * admin was last locked with it opens nothing, wherever the cookie went.
 */
final readonly class Passkey implements JsonSerializable
{
    /** How much of the key's digest a fingerprint shows: enough to tell two keys apart by eye. */
    private const int FINGERPRINT = 16;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $id       The credential id, base64url.
     * @param string $name     What it was called when it was enrolled.
     * @param string $key      The public key, as SPKI DER, raw.
     * @param int    $count    The last signature count it reported.
     * @param string $added    When it was enrolled, ISO 8601.
     * @param int    $unlocked When the challenge its last unlock answered was minted; 0 for never.
     * @param int    $locked   When the admin was last locked with it; 0 for never.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $key,
        public int    $count = 0,
        public string $added = '',
        public int    $unlocked = 0,
        public int    $locked = 0,
    ) {}

    /**
     * The passkey $data describes, or null where it does not describe one.
     *
     * A device enrolled before the store kept unlocks and locks has neither member, and reads as never
     * having done either.
     *
     * @param mixed $data One decoded member of the store.
     * @return self|null
     */
    public static function fromData(mixed $data): ?self
    {
        if (!$data instanceof stdClass) {
            return null;
        }

        $id       = $data->{PasskeyField::Id->value} ?? null;
        $name     = $data->{PasskeyField::Name->value} ?? null;
        $key      = $data->{PasskeyField::Key->value} ?? null;
        $count    = $data->{PasskeyField::Count->value} ?? null;
        $added    = $data->{PasskeyField::Added->value} ?? null;
        $unlocked = $data->{PasskeyField::Unlocked->value} ?? 0;
        $locked   = $data->{PasskeyField::Locked->value} ?? 0;
        $der      = is_string($key) ? Base64Url::decode($key) : null;

        if (
            !is_string($id) || !is_string($name) || $der === null || !is_int($count) || !is_string($added)
            || !is_int($unlocked) || !is_int($locked)
        ) {
            return null;
        }

        return new self($id, $name, $der, $count, $added, $unlocked, $locked);
    }

    /**
     * This passkey, having reported $count.
     *
     * @param int $count
     * @return self
     */
    public function counted(int $count): self
    {
        return new self($this->id, $this->name, $this->key, $count, $this->added, $this->unlocked, $this->locked);
    }

    /**
     * This passkey, having unlocked the admin over a challenge minted at $minted.
     *
     * @param int $minted
     * @return self
     */
    public function unlockedAt(int $minted): self
    {
        return new self($this->id, $this->name, $this->key, $this->count, $this->added, $minted, $this->locked);
    }

    /**
     * This passkey, having locked the admin at $now — never earlier than a lock it already recorded.
     *
     * @param int $now
     * @return self
     */
    public function lockedAt(int $now): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->key,
            $this->count,
            $this->added,
            $this->unlocked,
            max($now, $this->locked),
        );
    }

    /**
     * Whether $count may follow the count this passkey last reported.
     *
     * **A count that has been counting must rise.** An authenticator that counts signs with a number
     * one higher each time, so the same number twice is two authenticators answering for one key — a
     * cloned key. One that does not count reports zero, and that is accepted, since most passkeys synced
     * between devices do not count at all.
     *
     * @param int $count
     * @return bool
     */
    public function mayReport(int $count): bool
    {
        return ($count === 0 && $this->count === 0) || $count > $this->count;
    }

    /**
     * Whether a session this passkey unlocked at $since still opens the admin: only where it unlocked
     * after the admin was last locked with it.
     *
     * @param int $since
     * @return bool
     */
    public function opens(int $since): bool
    {
        return $since > $this->locked;
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
            PasskeyField::Id->value       => $this->id,
            PasskeyField::Name->value     => $this->name,
            PasskeyField::Key->value      => Base64Url::encode($this->key),
            PasskeyField::Count->value    => $this->count,
            PasskeyField::Added->value    => $this->added,
            PasskeyField::Unlocked->value => $this->unlocked,
            PasskeyField::Locked->value   => $this->locked,
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
