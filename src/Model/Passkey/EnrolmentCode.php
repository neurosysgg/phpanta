<?php

declare(strict_types=1);

namespace Phpanta\Model\Passkey;

use JsonException;
use Phpanta\Http\SessionSeal;
use Phpanta\Support\Base64Url;
use stdClass;

/**
 * The EnrolmentCode class. A device the admin's entrance registered, sealed, on its way to the signing
 * commands that decide whether it may come in.
 *
 * **The browser registers; only the key that signs pushes enrols.** A registration proves a device holds
 * a key and answered this deployment's challenge on this deployment's origin — nothing about whose
 * device it is. So the entrance stores nothing: it seals the device's credential id and public key
 * into a code, shows it with the key's fingerprint, and `access v1 enrol` — a signed write — is what
 * adds it. The seal is the deployment's own session key, so a code is one this deployment made, and it
 * carries a marker no session carries, so a session cookie is never a code. It is good for
 * {@link self::LIFETIME} seconds.
 */
final readonly class EnrolmentCode
{
    /** How long a code may be enrolled, in seconds. */
    public const int LIFETIME = 600;

    /** The member only a code carries, so nothing else sealed under the same key reads as one. */
    private const string MARKER = 'enrolment';

    /** A code is flat. */
    private const int MAX_DEPTH = 2;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $credential The credential id, base64url.
     * @param string $key        The public key, SPKI DER, raw.
     * @param int    $at         When the browser registered it.
     */
    public function __construct(
        public string $credential,
        public string $key,
        public int    $at,
    ) {}

    /**
     * The code, sealed — what the entrance shows.
     *
     * @param SessionSeal $seal
     * @return string
     */
    public function seal(SessionSeal $seal): string
    {
        return $seal->seal((string) json_encode([
            self::MARKER               => true,
            PasskeyField::Id->value    => $this->credential,
            PasskeyField::Key->value   => Base64Url::encode($this->key),
            PasskeyField::Added->value => $this->at,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * The code $sealed is, if this deployment sealed it and it is still good at $now — null otherwise.
     *
     * @param SessionSeal $seal
     * @param string      $sealed As pasted: surrounding whitespace is forgiven, nothing else is.
     * @param int         $now
     * @return self|null
     */
    public static function open(SessionSeal $seal, string $sealed, int $now): ?self
    {
        $opened = $seal->open(trim($sealed));

        try {
            $data = $opened === null ? null : json_decode($opened, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!$data instanceof stdClass || ($data->{self::MARKER} ?? null) !== true) {
            return null;
        }

        $credential = $data->{PasskeyField::Id->value} ?? null;
        $key        = $data->{PasskeyField::Key->value} ?? null;
        $at         = $data->{PasskeyField::Added->value} ?? null;
        $der        = is_string($key) ? Base64Url::decode($key) : null;

        if (!is_string($credential) || $der === null || !is_int($at) || $at > $now || $now - $at > self::LIFETIME) {
            return null;
        }

        return new self($credential, $der, $at);
    }

    /**
     * The key's fingerprint, as {@link Passkey::fingerprintOf()} writes it.
     *
     * @return string
     */
    public function fingerprint(): string
    {
        return Passkey::fingerprintOf($this->key);
    }
}
