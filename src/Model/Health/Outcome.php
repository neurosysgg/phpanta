<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

/**
 * The Outcome class. One {@link Requirement}, checked: what it found, and what that comes to.
 *
 * The verdict is asked of {@link Verdict::of()} rather than stored, so the one derivation from a
 * finding and a level is the only one there is.
 */
final readonly class Outcome
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Requirement $requirement
     * @param Finding $finding What {@link Requirement::check()} answered, asked exactly once.
     */
    public function __construct(public Requirement $requirement, public Finding $finding) {}

    /**
     * @return Verdict
     */
    public function verdict(): Verdict
    {
        return Verdict::of($this->finding->met, $this->requirement->level());
    }

    /**
     * The outcome's line: the name in its column, then the verdict, what was found, and the floor.
     *
     * **The verdict comes first after the name**, because it is the one column worth reading down:
     * what was found varies in width from `1` to an absolute path, so anything after it is ragged,
     * and the thing a reader scans a report for must not be. An optional requirement says so beside
     * its floor, which is what explains a `warn` where a reader expected a `FAIL`.
     *
     * @return HealthFact
     */
    public function fact(): HealthFact
    {
        return new HealthFact($this->requirement->name(), sprintf(
            '%-4s  %s  (%s%s)',
            $this->verdict()->label(),
            $this->finding->found === '' ? '-' : $this->finding->found,
            $this->requirement->expected(),
            $this->requirement->level() === Level::Optional ? ', ' . Level::Optional->value : '',
        ));
    }
}
