<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

/**
 * The Finding class. What one {@link Requirement} found when it was checked.
 *
 * Two facts and no verdict, deliberately: a requirement says what it saw and whether that meets its
 * floor, and {@link Verdict::of()} decides what that comes to at the requirement's level. Keeping
 * the verdict out of here is what keeps it out of every implementation — including one a user
 * writes, which could otherwise report its own failure as a warning.
 */
final readonly class Finding
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $found What the host has, as a reader would want it stated: `128M`, a version,
     *                      a path — or a sentence, where what was found is that something is not
     *                      there. `''` where there is nothing to state, which renders as a dash.
     * @param bool $met Whether that meets the requirement's floor.
     */
    public function __construct(public string $found, public bool $met) {}
}
