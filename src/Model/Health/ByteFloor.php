<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

use Phpanta\Exception\RequirementException;
use Phpanta\Support\Diagnostics;

/**
 * The ByteFloor class. A size directive — `memory_limit`, `post_max_size` — that has to allow at
 * least so many bytes.
 *
 * **The value is read with `ini_parse_quantity()`, PHP's own reader for exactly these strings**, so
 * `8M`, `8m`, `8388608` and `0x800000` all mean what the engine takes them to mean rather than what
 * a pattern written here would guess.
 *
 * **"Unlimited" is declared rather than assumed, because php.ini does not agree with itself about
 * it.** `memory_limit` spells it `-1`; `post_max_size` spells it `0`, which for `memory_limit` would
 * be a limit of nothing at all. A floor that assumed one sentinel would pass the other directive's
 * most broken value, so the declaration says which number means no limit, and a floor with none
 * declared has none.
 *
 * **A value the engine cannot read is not met**, although the engine itself reads it as `0` "for
 * backwards compatibility" — for `post_max_size` that fallback is *unlimited*. A php.ini typo is the
 * thing a health check exists to catch, so the diagnostic `ini_parse_quantity()` raises for one is
 * watched rather than muted and decides the answer.
 */
final readonly class ByteFloor implements SettingConstraint
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $bytes The least the directive may allow.
     * @param int|null $unlimitedAt The value this directive spells "no limit" with, which meets any
     *                              floor; null where it has none.
     *
     * @throws RequirementException if $bytes is negative, which no size can be.
     */
    public function __construct(public int $bytes, public ?int $unlimitedAt = null)
    {
        if ($bytes < 0) {
            throw new RequirementException(sprintf('A byte floor cannot be negative; %d was declared.', $bytes));
        }
    }

    /**
     * @param string $configured
     * @return bool
     */
    public function accepts(string $configured): bool
    {
        $parsed = Diagnostics::watched(static fn(): int => ini_parse_quantity($configured));

        if (!$parsed->reported->isEmpty()) {
            return false;
        }

        return $parsed->result === $this->unlimitedAt || $parsed->result >= $this->bytes;
    }

    /**
     * @return string
     */
    public function describe(): string
    {
        return 'at least ' . self::human($this->bytes)
            . ($this->unlimitedAt === null ? '' : sprintf(self::NO_LIMIT, $this->unlimitedAt));
    }

    /**
     * A byte count in php.ini's own shorthand, where it has an exact one.
     *
     * Exact or not at all: `8M` for 8,388,608 and the plain number for 8,000,000, rather than a
     * rounded `7.6M` that is not a value anyone could write back into php.ini.
     *
     * @param int $bytes
     * @return string
     */
    private static function human(int $bytes): string
    {
        return match (true) {
            $bytes === 0             => (string) $bytes,
            $bytes % (1 << 30) === 0 => ($bytes >> 30) . 'G',
            $bytes % (1 << 20) === 0 => ($bytes >> 20) . 'M',
            $bytes % (1 << 10) === 0 => ($bytes >> 10) . 'K',
            default                  => (string) $bytes,
        };
    }
}
