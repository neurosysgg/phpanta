<?php

declare(strict_types=1);

namespace Phpanta\Tool\Passkey;

use OpenSSLAsymmetricKey;
use Phpanta\Http\CsrfField;
use Phpanta\Http\Origin;
use Phpanta\Http\PasskeyFormField;
use Phpanta\Model\Passkey\CeremonyType;
use Phpanta\Support\Base64Url;
use Phpanta\Support\Collection;
use Phpanta\Support\File;
use Phpanta\Tool\Cli\UsageException;
use Phpanta\Tool\Http\FormField;

/**
 * The SoftwareDevice class. A passkey kept in a file: an ES256 key pair and the credential id it was
 * registered under, answering the admin's ceremonies the way a browser posts an authenticator's answer.
 *
 * **For a local copy of a site, and nothing else.** A browser on a machine with no platform
 * authenticator cannot make a passkey at all, so the admin's browser side would otherwise go untried
 * by hand. The key is a file on disk rather than secure hardware, which is fine for a deployment
 * that opens nothing anyone else can reach and would be a hole anywhere else — which is why the
 * command that plays one refuses any origin but a `*.localhost` one.
 *
 * What it answers is what an authenticator answers, byte for byte where the server reads bytes:
 * authenticator data is the relying party's hash, the flags and the count; client data is the JSON a
 * browser writes; the signature is DER over the data and the client data's hash. The flags say the
 * user was present and verified — this device has no user to ask, and the admin requires both.
 */
final readonly class SoftwareDevice
{
    /** User present, user verified: what an assertion reports. */
    private const int ASSERTED = 0x05;

    /** User present, user verified, attested credential data: what a registration reports. */
    private const int REGISTERED = 0x45;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string               $credential The credential id, base64url, as a browser writes it.
     * @param OpenSSLAsymmetricKey $key        The private key.
     * @param string               $pem        The private key, as it is kept.
     */
    private function __construct(
        public string $credential,
        private OpenSSLAsymmetricKey $key,
        private string $pem,
    ) {}

    /**
     * A new device: a fresh P-256 key and a random credential id.
     *
     * @return self
     * @throws UsageException if openssl cannot make the key.
     */
    public static function mint(): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($key === false || !openssl_pkey_export($key, $pem)) {
            throw new UsageException(
                'openssl could not make a P-256 key: ' . (openssl_error_string() ?: 'no reason given'),
            );
        }

        return new self(Base64Url::encode(random_bytes(16)), $key, (string) $pem);
    }

    /**
     * The device $file keeps, or null where there is no such file.
     *
     * @param File $file
     * @return self|null
     * @throws UsageException if the file is there and holds no device.
     */
    public static function fromFile(File $file): ?self
    {
        if (!$file->exists()) {
            return null;
        }

        $kept = json_decode((string) $file->read(), true);
        $pem  = is_array($kept) && is_string($kept['key'] ?? null) ? $kept['key'] : '';
        $id   = is_array($kept) && is_string($kept['credential'] ?? null) ? $kept['credential'] : '';
        $key  = $pem === '' ? false : openssl_pkey_get_private($pem);

        if ($key === false || Base64Url::decode($id) === null) {
            throw new UsageException(sprintf(
                '%s holds no device: it needs a credential id and a private key.',
                $file->path,
            ));
        }

        return new self($id, $key, $pem);
    }

    /**
     * Keeps this device in $file, readable by its owner alone, creating the directory it goes in.
     *
     * @param File $file
     * @return bool
     */
    public function keepIn(File $file): bool
    {
        $directory = $file->directory();

        if (!$directory->exists() && !$directory->create(0o700)) {
            return false;
        }

        $kept = json_encode(
            ['credential' => $this->credential, 'key' => $this->pem],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        return $file->write($kept . "\n", 0o600);
    }

    /**
     * The public key as SubjectPublicKeyInfo DER — what a browser's `getPublicKey()` hands over.
     *
     * @return string
     */
    public function publicKey(): string
    {
        $pem = (string) (openssl_pkey_get_details($this->key)['key'] ?? '');

        return (string) base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '', true);
    }

    /**
     * The key's fingerprint as the admin shows it: the first sixteen hex digits of its SPKI's
     * SHA-256, in fours.
     *
     * @return string
     */
    public function fingerprint(): string
    {
        return implode(' ', str_split(substr(hash('sha256', $this->publicKey()), 0, 16), 4));
    }

    /**
     * What a browser posts to register this device over $challenge, on $origin.
     *
     * @param string $challenge
     * @param Origin $origin
     * @return Collection<FormField>
     */
    public function registration(string $challenge, Origin $origin): Collection
    {
        $client = self::client(CeremonyType::Create, $challenge, $origin);
        $data   = self::data(self::REGISTERED, 0, $origin);

        return new Collection(FormField::class)->with(
            new FormField(PasskeyFormField::Credential->value, $this->credential),
            new FormField(PasskeyFormField::ClientData->value, Base64Url::encode($client)),
            new FormField(PasskeyFormField::AuthenticatorData->value, Base64Url::encode($data)),
            new FormField(PasskeyFormField::Key->value, Base64Url::encode($this->publicKey())),
        );
    }

    /**
     * What a browser posts when this device answers $challenge, on $origin.
     *
     * The count stays 0, as a platform authenticator's does: the admin then rests on the moment each
     * challenge was minted, which is the half worth trying.
     *
     * @param string $challenge
     * @param Origin $origin
     * @return Collection<FormField>
     */
    public function assertion(string $challenge, Origin $origin): Collection
    {
        $data   = self::data(self::ASSERTED, 0, $origin);
        $client = self::client(CeremonyType::Get, $challenge, $origin);

        openssl_sign($data . hash('sha256', $client, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return new Collection(FormField::class)->with(
            new FormField(PasskeyFormField::Credential->value, $this->credential),
            new FormField(PasskeyFormField::ClientData->value, Base64Url::encode($client)),
            new FormField(PasskeyFormField::AuthenticatorData->value, Base64Url::encode($data)),
            new FormField(PasskeyFormField::Signature->value, Base64Url::encode((string) $signature)),
        );
    }

    /**
     * A form's own fields ahead of $answer: the form token, then whatever else the form sends.
     *
     * @param string                $token
     * @param Collection<FormField> $answer
     * @param FormField             ...$fields
     * @return Collection<FormField>
     */
    public static function posting(string $token, Collection $answer, FormField ...$fields): Collection
    {
        return new Collection(FormField::class)->with(
            new FormField(CsrfField::Token->value, $token),
            ...$fields,
            ...$answer->toValues(),
        );
    }

    /**
     * Authenticator data: the relying party's hash, $flags, and $count.
     *
     * @param int    $flags
     * @param int    $count
     * @param Origin $origin
     * @return string
     */
    private static function data(int $flags, int $count, Origin $origin): string
    {
        return hash('sha256', $origin->host(), true) . chr($flags) . pack('N', $count);
    }

    /**
     * Client data, as a browser writes it for $type over $challenge on $origin.
     *
     * @param CeremonyType $type
     * @param string       $challenge
     * @param Origin       $origin
     * @return string
     */
    private static function client(CeremonyType $type, string $challenge, Origin $origin): string
    {
        return (string) json_encode(
            ['type' => $type->value, 'challenge' => $challenge, 'origin' => $origin->render(), 'crossOrigin' => false],
            JSON_UNESCAPED_SLASHES,
        );
    }
}
