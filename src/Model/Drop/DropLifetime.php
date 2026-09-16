<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

/**
 * The DropLifetime class. How long a drop is kept, as its maker writes it: a number and a
 * {@link DropUnit} — `90`, `30m`, `12h`, `7d`.
 *
 * At least {@link self::SHORTEST}, because a drop that is gone before its link has been sent is a
 * mistake rather than a choice; how long it may be at most is the deployment's, {@link DropConfig}.
 */
final class DropLifetime
{
    /** The shortest a drop is kept, in seconds: a minute. */
    public const int SHORTEST = 60;

    /** A number with no leading zero, eight digits at most, and a unit's letter or none. */
    private const string PATTERN = '/\A([1-9][0-9]{0,7})([smhd]?)\z/';

    /**
     * The seconds $text says, or null where it says none that is a lifetime.
     *
     * @param string $text
     * @return int|null
     */
    public static function parse(string $text): ?int
    {
        if (preg_match(self::PATTERN, $text, $matches) !== 1) {
            return null;
        }

        $seconds = (int) $matches[1] * (DropUnit::tryFrom($matches[2]) ?? DropUnit::Second)->seconds();

        return $seconds >= self::SHORTEST ? $seconds : null;
    }
}
