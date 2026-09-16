<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

use Phpanta\Support\Base64Url;
use SensitiveParameter;

/**
 * The DropToken class. The secret a drop's link carries: thirty-two random bytes, base64url after the
 * `#` — `https://example.org/drop#<token>`.
 *
 * **The server never keeps it.** A drop's file is named by a keyed hash of it and sealed under a key
 * drawn from it and the deployment's own key, so the disk and the key together open nothing without
 * the link, and a link opens nothing without the deployment. It rides after the `#`, which a browser
 * never sends, so it is in no request line and no access log; the page's script hands it over in the
 * body of the post that reveals the drop. See {@link \Phpanta\Service\Drop\DropCipher}.
 */
final readonly class DropToken
{
    /** How many random bytes a token is — as many as the key it opens. */
    public const int BYTES = 32;

    /**
     * @param string $bytes Exactly {@link self::BYTES} bytes.
     */
    private function __construct(#[SensitiveParameter] private string $bytes) {}

    /**
     * A new token, from the system's random source.
     *
     * @return self
     */
    public static function mint(): self
    {
        return new self(random_bytes(self::BYTES));
    }

    /**
     * The token $text is, or null where it is not one — not base64url, or not {@link self::BYTES} bytes.
     *
     * @param string $text
     * @return self|null
     */
    public static function read(#[SensitiveParameter] string $text): ?self
    {
        $bytes = Base64Url::decode($text);

        return $bytes !== null && strlen($bytes) === self::BYTES ? new self($bytes) : null;
    }

    /**
     * The token's bytes.
     *
     * @return string
     */
    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * The token as a link carries it: base64url, forty-three characters.
     *
     * @return string
     */
    public function text(): string
    {
        return Base64Url::encode($this->bytes);
    }
}
