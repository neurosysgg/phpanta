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
     * The outcome as a fact: the requirement's name, its verdict, then what was found and the floor.
     *
     * The verdict rides on the fact rather than inside its value, so a page can put it in a column
     * of its own and data can name it; {@link HealthFact::render()} writes it first after the name,
     * which is where the text has always had it. An optional requirement says so beside its floor,
     * which is what explains a `warn` where a reader expected a `FAIL`.
     *
     * @return HealthFact
     */
    public function fact(): HealthFact
    {
        return new HealthFact($this->requirement->name(), sprintf(
            '%s  (%s%s)',
            $this->finding->found === '' ? '-' : $this->finding->found,
            $this->requirement->expected(),
            $this->requirement->level() === Level::Optional ? ', ' . Level::Optional->value : '',
        ), $this->verdict());
    }
}
