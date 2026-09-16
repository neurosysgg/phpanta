<?php

declare(strict_types=1);

namespace Phpanta\Service\Drop;

use Phpanta\App;
use Phpanta\CredentialFile;
use Phpanta\Exception\InvalidValueException;
use Phpanta\Http\SessionSeal;
use Phpanta\Model\Drop\DropHeader;
use Phpanta\Model\Drop\DropKeys;
use Phpanta\Model\Drop\DropToken;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\File;
use Phpanta\Support\PublicKey;
use SensitiveParameter;

/**
 * The DropCipher class. What a drop is sealed with: the deployment's drop key, the link's token and,
 * where it has one, a password — and the one place under `src/` a drop's bytes are encrypted or
 * opened.
 *
 * **Two secrets, held apart.** A drop's keys are drawn by HKDF-SHA256 from its token — and the
 * password, stretched — with the deployment's key as the salt, which makes the extraction an HMAC
 * keyed by the deployment. So the server's disk and its key together open nothing without the link,
 * and the link opens nothing without the deployment: taking the key away, or the file it lives in,
 * destroys every drop at once. The server sees a token only while it answers the post carrying it,
 * and keeps none; a drop's file is named by another HMAC of it ({@link self::idOf()}).
 *
 * **A password is stretched once and mixed in, not sealed around the rest.** PBKDF2-SHA256, at
 * {@link self::ITERATIONS} rounds with the drop's own salt, into the same key: as strong as sealing
 * twice, one pass over the bytes rather than two, and every guess costs the guesser the whole
 * stretch. `ext/sodium` would offer Argon2id; the local runtimes do not have it, and a code path only
 * production takes is one no test reaches.
 *
 * **AES-256-GCM, {@link SessionSeal}'s cipher, in chunks.** Each {@link DropHeader::CHUNK} of the
 * bytes is sealed on its own, under a nonce that counts it and with the header, its index and whether
 * it is the last bound in — so a drop streams out a chunk at a time, every chunk checked before it is
 * sent, and one cut short, reordered or grown does not open. The counter can serve as the nonce
 * because a key is never used for a second drop: each is drawn from a token of its own.
 */
final readonly class DropCipher
{
    /** How many bytes the deployment's key is. */
    public const int KEY_BYTES = 32;

    /**
     * PBKDF2-SHA256's rounds for a password — OWASP's figure for it in 2023, about a quarter of a
     * second of a request here. Kept in each drop's header, so raising it opens every older drop still.
     */
    public const int ITERATIONS = 600_000;

    /** What a drop's keys are drawn for, beside its salt — so they are never another use's. */
    private const string INFO = 'phpanta-drop/1';

    /** What a drop's name in the store is drawn for. */
    private const string NAMING = 'phpanta-drop/id';

    /** How many hex digits a drop's name is: a hundred and twenty-eight bits. */
    private const int ID_CHARS = 32;

    /** GCM's nonce is twelve bytes: four of zero, then the eight-byte counter. */
    private const int NONCE_PAD = 4;

    /**
     * @param string $key Exactly {@link self::KEY_BYTES} bytes.
     */
    private function __construct(#[SensitiveParameter] private string $key) {}

    /**
     * A cipher under $key.
     *
     * @param string $key
     * @return self
     * @throws InvalidValueException if the key is not {@link self::KEY_BYTES} bytes.
     */
    public static function fromKey(#[SensitiveParameter] string $key): self
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new InvalidValueException(sprintf('A drop key is %d bytes, not %d.', self::KEY_BYTES, strlen($key)));
        }

        return new self($key);
    }

    /**
     * The cipher under the key in `data/drop.key` — or null where there is none there, or the file
     * does not hold one: thirty-two bytes, base64, and nothing else but whitespace.
     *
     * @param File|null $file A test seam; null is the deployment's own.
     * @return self|null
     */
    public static function current(?File $file = null): ?self
    {
        $encoded = ($file ?? App::current()->dataFile(CredentialFile::DropKey))->read();
        $key     = $encoded === null ? false : base64_decode(trim($encoded), true);

        return is_string($key) && strlen($key) === self::KEY_BYTES ? new self($key) : null;
    }

    /**
     * How a key for $file is minted — what the admin says where there is none.
     *
     * @param File $file
     * @return string
     */
    public static function minting(File $file): string
    {
        return sprintf(
            "mint one on the host it serves — php -r 'echo base64_encode(random_bytes(%d)), PHP_EOL;' > %s"
            . ' — and never commit or deploy it',
            self::KEY_BYTES,
            $file->path,
        );
    }

    /**
     * The name the drop $token opens is kept under: thirty-two hex digits of an HMAC of it, keyed by
     * the deployment — so a name says nothing about the token, and a token names one drop.
     *
     * @param DropToken $token
     * @return string
     */
    public function idOf(DropToken $token): string
    {
        return substr(hash_hmac(PublicKey::DIGEST, self::NAMING . $token->bytes(), $this->key), 0, self::ID_CHARS);
    }

    /**
     * The keys the drop $header begins is sealed under, drawn from $token, $password where it needs
     * one, and the deployment's key.
     *
     * @param DropToken $token
     * @param string    $password Ignored where the header says the drop needs none.
     * @param DropHeader $header
     * @return DropKeys
     */
    public function keys(DropToken $token, #[SensitiveParameter] string $password, DropHeader $header): DropKeys
    {
        $stretched = $header->locked
            ? hash_pbkdf2(PublicKey::DIGEST, $password, $header->salt, $header->iterations, self::KEY_BYTES, true)
            : '';

        $keys = hash_hkdf(
            PublicKey::DIGEST,
            $token->bytes() . $stretched,
            2 * self::KEY_BYTES,
            self::INFO . $header->salt,
            $this->key,
        );

        return new DropKeys(substr($keys, 0, self::KEY_BYTES), substr($keys, self::KEY_BYTES));
    }

    /**
     * $plaintext sealed under $key as the $index-th thing sealed under it, with $data bound in: the
     * ciphertext, then the tag.
     *
     * @param string $plaintext
     * @param string $key
     * @param int    $index
     * @param string $data
     * @return string
     */
    public function seal(string $plaintext, #[SensitiveParameter] string $key, int $index, string $data): string
    {
        $tag = '';

        // The cipher, the key's length and the nonce's are all fixed here, which are the only things
        // that make the encryption itself fail — so there is no failure to branch on. SessionSeal says
        // the same of its own seal.
        $ciphertext = (string) openssl_encrypt(
            $plaintext,
            SessionSeal::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            self::nonce($index),
            $tag,
            $data,
            DropHeader::TAG,
        );

        return $ciphertext . $tag;
    }

    /**
     * What $sealed holds, or null where it does not open as the $index-th thing sealed under $key with
     * $data — tampered with, cut short, sealed elsewhere, or under another key.
     *
     * @param string $sealed
     * @param string $key
     * @param int    $index
     * @param string $data
     * @return string|null
     */
    public function open(string $sealed, #[SensitiveParameter] string $key, int $index, string $data): ?string
    {
        if (strlen($sealed) < DropHeader::TAG) {
            return null;
        }

        $plaintext = Diagnostics::muted(static fn(): mixed => openssl_decrypt(
            substr($sealed, 0, -DropHeader::TAG),
            SessionSeal::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            self::nonce($index),
            substr($sealed, -DropHeader::TAG),
            $data,
        ));

        return is_string($plaintext) ? $plaintext : null;
    }

    /**
     * The nonce of the $index-th seal under a key.
     *
     * @param int $index
     * @return string
     */
    private static function nonce(int $index): string
    {
        return str_repeat(chr(0), self::NONCE_PAD) . DropHeader::counter($index);
    }
}
