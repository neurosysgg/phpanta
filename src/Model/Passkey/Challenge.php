<?php

declare(strict_types=1);

namespace Phpanta\Model\Passkey;

use Phpanta\Http\Input;
use Phpanta\Support\Base64Url;

/**
 * The Challenge class. Thirty-two random bytes a page hands a browser to sign, kept in the visitor's
 * sealed session until the answer comes back.
 *
 * **Minted for one thing, and for a short while.** A challenge names its {@link ChallengePurpose}, and a
 * write's names the one address and method it was minted for — its binding — so an answer to it opens
 * nothing else. It lasts {@link self::LIFETIME} seconds, which is long enough to touch a key and not
 * long enough to be worth stealing; and the session it rides in is replaced by the answer, so it is
 * spent once.
 */
final readonly class Challenge
{
    /** How long a challenge may be answered, in seconds. */
    public const int LIFETIME = 120;

    /** How many random bytes it is. */
    private const int BYTES = 32;

    /** Between the parts a session keeps it as; nothing before the binding can contain it. */
    private const string SEPARATOR = '|';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param ChallengePurpose $purpose What it was minted for.
     * @param string           $value   The bytes, base64url — what the browser signs, and what comes back.
     * @param int              $expires When it stops being answerable.
     * @param string           $bound   For a write, `METHOD path`; empty otherwise.
     */
    private function __construct(
        public ChallengePurpose $purpose,
        public string           $value,
        public int              $expires,
        public string           $bound,
    ) {}

    /**
     * A new challenge for $purpose, answerable for {@link self::LIFETIME} seconds from $now.
     *
     * @param ChallengePurpose $purpose
     * @param int              $now
     * @param string           $bound
     * @return self
     */
    public static function mint(ChallengePurpose $purpose, int $now, string $bound = ''): self
    {
        return new self($purpose, Base64Url::encode(random_bytes(self::BYTES)), $now + self::LIFETIME, $bound);
    }

    /**
     * The challenge a session kept as $stored, or null where it is not one.
     *
     * @param string $stored
     * @return self|null
     */
    public static function fromStored(string $stored): ?self
    {
        $parts = explode(self::SEPARATOR, $stored, 4);

        if (count($parts) !== 4) {
            return null;
        }

        [$purpose, $expires, $value, $bound] = $parts;
        $minted = ChallengePurpose::tryFrom($purpose);

        return $minted === null || preg_match(Input::WHOLE_NUMBER, $expires) !== 1
            ? null
            : new self($minted, $value, (int) $expires, $bound);
    }

    /**
     * The challenge as a session keeps it.
     *
     * @return string
     */
    public function stored(): string
    {
        return implode(self::SEPARATOR, [$this->purpose->value, $this->expires, $this->value, $this->bound]);
    }

    /**
     * When this challenge was minted — the serial a browser's write carries, and what an unlock must be
     * newer than.
     *
     * @return int
     */
    public function minted(): int
    {
        return $this->expires - self::LIFETIME;
    }

    /**
     * Whether this challenge may be answered now, for $purpose, at $bound.
     *
     * @param ChallengePurpose $purpose
     * @param int              $now
     * @param string           $bound
     * @return bool
     */
    public function expects(ChallengePurpose $purpose, int $now, string $bound = ''): bool
    {
        return $this->purpose === $purpose && $this->bound === $bound && $now <= $this->expires;
    }
}
