<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

/**
 * The Verdict enum. What a checked {@link Requirement} came to.
 *
 * **Derived, never declared.** A requirement reports what it found and whether that meets its floor
 * — a {@link Finding} — and {@link self::of()} turns that and the requirement's {@link Level} into
 * one of these. So no implementation, least of all one a user writes, can decide for itself that
 * its own failure is only a warning.
 *
 * Three, which is the shape health checks commonly take — pass and warn answer `200`, fail answers
 * `503` — and which {@link HealthResult::status()} maps.
 *
 * Server-only; no TypeScript mirror is wanted — see {@link Area}.
 */
enum Verdict: string
{
    case Pass = 'pass';

    case Warn = 'warn';

    case Fail = 'fail';

    /**
     * The verdict a finding comes to at a level.
     *
     * @param bool $met Whether what was found meets the floor.
     * @param Level $level
     * @return self
     */
    public static function of(bool $met, Level $level): self
    {
        return match (true) {
            $met                       => self::Pass,
            $level === Level::Optional => self::Warn,
            default                    => self::Fail,
        };
    }

    /**
     * How the verdict reads in a report.
     *
     * The value, except that a failure is upper case — the one line in a report worth catching an
     * eye, and the convention the extension report's `MISSING` set before this existed.
     *
     * @return string
     */
    public function label(): string
    {
        return $this === self::Fail ? strtoupper($this->value) : $this->value;
    }
}
