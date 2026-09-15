<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use Phpanta\Http\Api\ResultKey;
use Phpanta\Http\Api\ResultSection;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Support\Collection;
use Phpanta\Text\AdminText;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\MachineAttribute;
use Phpanta\View\Html\MachineTag;
use Phpanta\View\Html\Node;

/**
 * The StatsSection class. The machine's live readings: how busy, how full, how fast.
 *
 * **Written whole by the server, kept live by the page.** The facts are what one moment says — memory
 * and swap in use, the bytes the network has moved, the load — and a processor's busyness, which one
 * moment cannot say, is left blank. `<machine-stats>` asks {@link self::$source} for the same answer
 * as data every few seconds and works each reading out from two sets of {@link MachineCounters}, so
 * the page shows rates where the text shows totals. Without the script it is a table of the moment
 * the page was made.
 */
final readonly class StatsSection implements ResultSection
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param MachineCounters $counters What the machine said.
     * @param string          $source   The address this answer is at, which the page asks again.
     */
    public function __construct(private MachineCounters $counters, private string $source) {}

    /**
     * @return string
     */
    public function render(): string
    {
        return $this->text()->render();
    }

    /**
     * @return Node
     */
    public function node(): Node
    {
        $rows = [];

        foreach (MachineReading::cases() as $reading) {
            $percent = $this->percent($reading);
            $rows[]  = new Element(HtmlTag::Tr)->containing(
                new Element(HtmlTag::Td)->containing($reading->value),
                $percent === null ? new Element(HtmlTag::Td) : new Element(HtmlTag::Td)->containing(
                    new Element(HtmlTag::Meter)
                        ->attr(MachineAttribute::Reading, $reading)
                        ->attr(HtmlAttribute::Min, 0)
                        ->attr(HtmlAttribute::Max, 100)
                        ->attr(HtmlAttribute::Value, $percent),
                ),
                new Element(HtmlTag::Td)->attr(MachineAttribute::Reading, $reading)->containing($this->shown($reading)),
            );
        }

        return new Element(HtmlTag::Section)->containing(
            new Element(HtmlTag::H2)->containing(AdminText::Live),
            new Element(MachineTag::Stats)
                ->attr(MachineAttribute::Source, $this->source)
                ->containing(new Element(HtmlTag::Table)->containing(...$rows)),
        );
    }

    /**
     * The readings as a section of facts, and the counters beside them under their own key.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return [...(array) $this->text()->jsonSerialize(), ResultKey::Counters->value => $this->counters];
    }

    /**
     * The readings one moment can give, as facts.
     *
     * @return HealthSection
     */
    private function text(): HealthSection
    {
        $facts = new Collection(HealthFact::class);

        foreach (MachineReading::cases() as $reading) {
            $facts = $facts->with(new HealthFact($reading->value, $this->shown($reading)));
        }

        return HealthSection::facts(MachineArea::Live->value, $facts);
    }

    /**
     * What $reading reads at this one moment — nothing, for one that needs two.
     *
     * @param MachineReading $reading
     * @return string
     */
    private function shown(MachineReading $reading): string
    {
        $counters = $this->counters;

        return match ($reading) {
            MachineReading::Cpu      => '',
            MachineReading::Memory   => Measure::share($counters->memoryUsed, $counters->memoryTotal),
            MachineReading::Swap     => $counters->swapTotal > 0
                ? Measure::share($counters->swapUsed, $counters->swapTotal)
                : '',
            MachineReading::Received => Measure::bytes($counters->received),
            MachineReading::Sent     => Measure::bytes($counters->sent),
            MachineReading::Load     => sprintf('%.2F', $counters->load / 100),
        };
    }

    /**
     * How full $reading's meter is, or null for a reading with no meter.
     *
     * @param MachineReading $reading
     * @return int|null
     */
    private function percent(MachineReading $reading): ?int
    {
        $counters = $this->counters;

        return match ($reading) {
            MachineReading::Cpu    => 0,
            MachineReading::Memory => self::share($counters->memoryUsed, $counters->memoryTotal),
            MachineReading::Swap   => self::share($counters->swapUsed, $counters->swapTotal),
            default                => null,
        };
    }

    /**
     * $part of $whole in whole percent, nothing of nothing being none.
     *
     * @param int $part
     * @param int $whole
     * @return int
     */
    private static function share(int $part, int $whole): int
    {
        return $whole > 0 ? (int) round($part * 100 / $whole) : 0;
    }
}
