<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Exception\SessionException;
use Phpanta\Support\Base64Url;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\File;
use SensitiveParameter;

/**
 * The SessionSeal class. What a session is sealed with before it goes into a cookie, and opened with
 * when it comes back.
 *
 * **Sealed, not signed.** AES-256-GCM encrypts and authenticates in one step: a visitor can neither
 * read what their session holds nor change a byte of it without the whole of it failing to open.
 * `ext/openssl` is already required for the API's signatures, so this costs the host nothing new.
 * Every seal carries a fresh random nonce, and what is sealed — a {@link SealContext} — is bound in as
 * associated data, so bytes sealed as one thing cannot be opened as another under the same key.
 *
 * **The key is per deployment, and never ships.** It lives in `data/session.key` — thirty-two random
 * bytes, base64 — minted on the host it serves, gitignored, and excluded from a deploy the way the
 * other credentials are. A deployment without one cannot seal a session, and says so loudly the
 * first time something asks it to.
 *
 * What does not open is null, never an exception: tampering, another key, another context, a
 * truncated cookie — each is simply no session.
 */
final readonly class SessionSeal
{
    /**
     * The cipher. Authenticated, so opening is also checking. Public because it is the framework's one
     * AEAD, and a drop is sealed with it too — {@link \Phpanta\Service\Drop\DropCipher}.
     */
    public const string CIPHER = 'aes-256-gcm';

    /** The key's length, in bytes. */
    private const int KEY_BYTES = 32;

    /** GCM's nonce, in bytes — random per seal. */
    private const int NONCE_BYTES = 12;

    /** GCM's tag, in bytes — the whole of the authentication. */
    private const int TAG_BYTES = 16;

    /**
     * @param string $key Exactly {@link self::KEY_BYTES} bytes.
     */
    private function __construct(#[SensitiveParameter] private string $key) {}

    /**
     * A seal under $key.
     *
     * @param string $key
     * @return self
     * @throws SessionException if the key is not exactly thirty-two bytes.
     */
    public static function fromKey(#[SensitiveParameter] string $key): self
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new SessionException(sprintf('A session key is %d bytes, not %d.', self::KEY_BYTES, strlen($key)));
        }

        return new self($key);
    }

    /**
     * The seal under the key in $file: thirty-two bytes, base64, and nothing else but whitespace.
     *
     * @param File $file
     * @return self
     * @throws SessionException if the file is not there, or does not hold such a key.
     */
    public static function fromFile(File $file): self
    {
        $encoded = $file->read();

        if ($encoded === null) {
            throw new SessionException(sprintf(
                'Sessions need a key in %s, and there is none. Mint one on the host it serves: '
                . "php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' > %s — and never commit or deploy it.",
                $file->path,
                $file->path,
            ));
        }

        $key = base64_decode(trim($encoded), true);

        return $key === false
            ? throw new SessionException(sprintf('%s does not hold a base64 key.', $file->path))
            : self::fromKey($key);
    }

    /**
     * $plaintext, sealed as $context: the nonce, the tag and the ciphertext, base64url — safe in a
     * cookie as it is.
     *
     * @param string      $plaintext
     * @param SealContext $context
     * @return string
     */
    public function seal(string $plaintext, SealContext $context): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag   = '';

        // The cipher, the key's length and the nonce's are all fixed here, which are the only things
        // that make the encryption itself fail — so there is no failure to branch on.
        $ciphertext = (string) openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $context->value,
            self::TAG_BYTES,
        );

        return Base64Url::encode($nonce . $tag . $ciphertext);
    }

    /**
     * What $sealed holds, or null if it does not open as $context under this key — tampered with,
     * sealed under another key or as something else, cut short, or not a seal at all.
     *
     * @param string      $sealed
     * @param SealContext $context
     * @return string|null
     */
    public function open(string $sealed, SealContext $context): ?string
    {
        $raw = Base64Url::decode($sealed);

        if ($raw === null || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            return null;
        }

        $plaintext = Diagnostics::muted(fn(): mixed => openssl_decrypt(
            substr($raw, self::NONCE_BYTES + self::TAG_BYTES),
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::NONCE_BYTES),
            substr($raw, self::NONCE_BYTES, self::TAG_BYTES),
            $context->value,
        ));

        return is_string($plaintext) ? $plaintext : null;
    }
}
