<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

use NoDiscard;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Support\Collection;

/**
 * The HealthResult class. A set of requirements, checked, and what the set comes to.
 *
 * **The status is the verdict's, and the body is always there.** Any `fail` is a `503`; warnings
 * alone are a `200`, because an optional requirement going unmet is worth reading and not worth
 * paging anyone for. Either way the body is the whole report — a `503` with nothing in it would say
 * that something is wrong and withhold which, from the one caller who has proved they may know.
 *
 * Every requirement is checked **exactly once**, when the result is built: {@link self::of()}
 * settles the collection, so rendering and deciding the status read the same findings rather than
 * asking the host twice and risking two answers.
 */
final readonly class HealthResult
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<Outcome> $outcomes Already checked; see {@link self::of()}.
     */
    private function __construct(private Collection $outcomes) {}

    /**
     * Checks $requirements — or the ones in $area, where one is given.
     *
     * @param Collection<Requirement> $requirements
     * @param Area|null $area Null for every area.
     * @return self
     */
    public static function of(Collection $requirements, ?Area $area = null): self
    {
        return new self($requirements
            ->where(static fn(Requirement $requirement): bool => $area === null || $requirement->area() === $area)
            ->map(static fn(Requirement $requirement): Outcome => new Outcome($requirement, $requirement->check()))
            ->settled());
    }

    /**
     * `503` if anything required went unmet, `200` otherwise.
     *
     * @return HttpStatusCode
     */
    #[NoDiscard('status() answers a question and changes nothing, so a call whose result goes nowhere does nothing')]
    public function status(): HttpStatusCode
    {
        return $this->count(Verdict::Fail) === 0 ? HttpStatusCode::Ok : HttpStatusCode::ServiceUnavailable;
    }

    /**
     * One section per area with anything in it, in {@link Area}'s order, then the tally as a block
     * of its own with no caption.
     *
     * An area with nothing declared is left out rather than printed empty — the tally still says
     * how many were checked, and a caption over nothing reads as a section that failed to render.
     *
     * @return Collection<HealthSection>
     */
    #[NoDiscard('sections() answers with the report and changes nothing, so a dropped result does nothing')]
    public function sections(): Collection
    {
        $sections = new Collection(HealthSection::class);

        foreach (Area::cases() as $area) {
            $in = $this->outcomes->where(static fn(Outcome $outcome): bool => $outcome->requirement->area() === $area);

            if (!$in->isEmpty()) {
                $sections = $sections->with(HealthSection::facts(
                    $area->value,
                    $in->map(static fn(Outcome $outcome): HealthFact => $outcome->fact()),
                ));
            }
        }

        return $sections->with(HealthSection::lines(null, $this->tally()));
    }

    /**
     * The report as text: {@link self::sections()}, a blank line between each.
     *
     * @return string
     */
    #[NoDiscard('render() answers with the report and changes nothing, so a dropped result does nothing')]
    public function render(): string
    {
        return $this->sections()->map(static fn(HealthSection $section): string => $section->render())->join("\n\n");
    }

    /**
     * How many of each verdict, every verdict named even at zero — `0 fail` is the line a reader of
     * a healthy report is looking for, and its absence would make them look for it.
     *
     * @return string
     */
    private function tally(): string
    {
        return new Collection(Verdict::class)
            ->with(...Verdict::cases())
            ->map(fn(Verdict $verdict): string => $this->count($verdict) . ' ' . $verdict->value)
            ->join(', ');
    }

    /**
     * @param Verdict $verdict
     * @return int
     */
    private function count(Verdict $verdict): int
    {
        return $this->outcomes->where(static fn(Outcome $outcome): bool => $outcome->verdict() === $verdict)->count();
    }
}
