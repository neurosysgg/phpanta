<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

/**
 * The Measure class. How the `machine` service writes a quantity for a reader: bytes in binary
 * units, a stretch of time in days, hours and minutes, a share in percent, a moment to the minute.
 *
 * One place, so a disk and a file and a process are measured in the same words.
 */
final class Measure
{
    /** The binary units after a byte, each 1024 of the one before. */
    private const string UNITS = 'KMGTPE';

    /**
     * $bytes in the largest binary unit that keeps it at one or more — `512 B`, `1.5 KiB`, `931.5 GiB`.
     *
     * @param int $bytes
     * @return string
     */
    public static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $value = (float) $bytes;
        $unit  = -1;

        while ($value >= 1024 && $unit < strlen(self::UNITS) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.1F %siB', $value, self::UNITS[$unit]);
    }

    /**
     * $seconds as days, hours and minutes, leaving out a leading zero — `3 d 4 h 12 min`, `12 min`.
     *
     * @param int $seconds
     * @return string
     */
    public static function duration(int $seconds): string
    {
        $days    = intdiv($seconds, 86_400);
        $hours   = intdiv($seconds % 86_400, 3_600);
        $minutes = intdiv($seconds % 3_600, 60);

        return match (true) {
            $days > 0  => sprintf('%d d %d h %d min', $days, $hours, $minutes),
            $hours > 0 => sprintf('%d h %d min', $hours, $minutes),
            default    => sprintf('%d min', $minutes),
        };
    }

    /**
     * $part of $whole in whole percent — `0%` where the whole is nothing.
     *
     * @param int $part
     * @param int $whole
     * @return string
     */
    public static function percent(int $part, int $whole): string
    {
        return ($whole > 0 ? (int) round($part * 100 / $whole) : 0) . '%';
    }

    /**
     * $part of $whole of something measured in bytes — `412.3 GiB of 931.5 GiB (44%)`.
     *
     * @param int $part
     * @param int $whole
     * @return string
     */
    public static function share(int $part, int $whole): string
    {
        return sprintf('%s of %s (%s)', self::bytes($part), self::bytes($whole), self::percent($part, $whole));
    }

    /**
     * The moment $timestamp names, to the minute, in the server's zone.
     *
     * @param int $timestamp
     * @return string
     */
    public static function moment(int $timestamp): string
    {
        return date('Y-m-d H:i', $timestamp);
    }
}
