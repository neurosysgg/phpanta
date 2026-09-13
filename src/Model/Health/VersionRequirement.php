<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

use Phpanta\Exception\RequirementException;

/**
 * The VersionRequirement class. The oldest PHP an installation will run on.
 *
 * Compared with `version_compare()` against `PHP_VERSION`, which is the engine's own reading of its
 * own version, and declared as a dotted number rather than a `PHP_VERSION_ID` — the form a reader
 * would write, and the form `composer.json` states it in.
 */
final readonly class VersionRequirement implements Requirement
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $minimum Either `major.minor` or `major.minor.patch`.
     * @param Level $level
     *
     * @throws RequirementException if $minimum is not a version, which `version_compare()` would
     *                              otherwise compare anyway and answer something for.
     */
    public function __construct(public string $minimum, private Level $level = Level::Required)
    {
        if (preg_match('/^\d+\.\d+(?:\.\d+)?\z/', $minimum) !== 1) {
            throw new RequirementException(sprintf("'%s' is not a PHP version.", $minimum));
        }
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return 'php';
    }

    /**
     * @return Area
     */
    public function area(): Area
    {
        return Area::Runtime;
    }

    /**
     * @return Level
     */
    public function level(): Level
    {
        return $this->level;
    }

    /**
     * @return string
     */
    public function expected(): string
    {
        return $this->minimum . ' or later';
    }

    /**
     * @return Finding
     */
    public function check(): Finding
    {
        return new Finding(PHP_VERSION, version_compare(PHP_VERSION, $this->minimum, '>='));
    }
}
