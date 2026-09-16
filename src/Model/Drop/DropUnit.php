<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

/**
 * The DropUnit enum. What a drop's lifetime may be counted in — the letter after its number, `30m`,
 * `12h`, `7d`. A number with no letter is seconds.
 */
enum DropUnit: string
{
    case Second = 's';
    case Minute = 'm';
    case Hour   = 'h';
    case Day    = 'd';

    /**
     * How many seconds one of this unit is.
     *
     * @return int
     */
    public function seconds(): int
    {
        return match ($this) {
            self::Second => 1,
            self::Minute => 60,
            self::Hour   => 3_600,
            self::Day    => 86_400,
        };
    }
}
