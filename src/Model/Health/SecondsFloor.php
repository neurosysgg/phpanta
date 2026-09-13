<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

use Phpanta\Exception\RequirementException;

/**
 * The SecondsFloor class. A duration directive — `max_execution_time` — that has to allow at least
 * so many seconds.
 *
 * **`'0'` is the value that matters, and it is the one a careless floor gets wrong twice.**
 * `max_execution_time` is `0` on a runtime with no limit at all, which meets any floor — but `0` is
 * below every floor numerically, and `'0'` is falsy as a string. So "unlimited" is declared, the way
 * {@link ByteFloor} declares it and for its reason: php.ini does not spell it one way.
 *
 * A value that is not a whole number of seconds is not met, rather than read as whatever `(int)`
 * would make of it.
 */
final readonly class SecondsFloor implements SettingConstraint
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $seconds The least the directive may allow.
     * @param int|null $unlimitedAt The value this directive spells "no limit" with; null where it has
     *                              none.
     *
     * @throws RequirementException if $seconds is negative, which no duration can be.
     */
    public function __construct(public int $seconds, public ?int $unlimitedAt = null)
    {
        if ($seconds < 0) {
            throw new RequirementException(sprintf(
                'A floor in seconds cannot be negative; %d was declared.',
                $seconds,
            ));
        }
    }

    /**
     * @param string $configured
     * @return bool
     */
    public function accepts(string $configured): bool
    {
        $value = filter_var($configured, FILTER_VALIDATE_INT);

        return $value !== false && ($value === $this->unlimitedAt || $value >= $this->seconds);
    }

    /**
     * @return string
     */
    public function describe(): string
    {
        return sprintf('at least %ds', $this->seconds)
            . ($this->unlimitedAt === null ? '' : sprintf(self::NO_LIMIT, $this->unlimitedAt));
    }
}
