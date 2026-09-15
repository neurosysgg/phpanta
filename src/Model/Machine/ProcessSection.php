<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use Phpanta\Http\Api\ResultKey;
use Phpanta\Http\Api\ResultSection;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Support\Collection;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;

/**
 * The ProcessSection class. The processes a machine is running, as a table — the ones holding the
 * most memory first.
 */
final readonly class ProcessSection implements ResultSection
{
    /** What a machine that cannot say what it runs is said to say. */
    private const string SILENT = 'this machine does not say what it is running';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<MachineProcess> $processes In the order they are shown.
     */
    public function __construct(private Collection $processes) {}

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
        if ($this->processes->isEmpty()) {
            return $this->text()->node();
        }

        $columns = [
            ResultKey::Pid,
            ResultKey::Owner,
            ResultKey::State,
            ResultKey::Memory,
            ResultKey::Cpu,
            ResultKey::Started,
            ResultKey::Command,
        ];
        $heading = [];

        foreach ($columns as $key) {
            $heading[] = new Element(HtmlTag::Th)->containing($key->value);
        }

        $rows = $this->processes->map(static fn(MachineProcess $process): Node => new Element(HtmlTag::Tr)->containing(
            new Element(HtmlTag::Td)->containing((string) $process->pid),
            new Element(HtmlTag::Td)->containing($process->owner),
            new Element(HtmlTag::Td)->containing($process->state),
            new Element(HtmlTag::Td)->containing(Measure::bytes($process->memory)),
            new Element(HtmlTag::Td)->containing(Measure::duration($process->cpu)),
            new Element(HtmlTag::Td)->containing(Measure::moment($process->started)),
            new Element(HtmlTag::Td)->containing($process->command),
        ));

        $table = new Element(HtmlTag::Table)
            ->containing(new Element(HtmlTag::Tr)->containing(...$heading), ...$rows->toValues());

        return new Element(HtmlTag::Section)->containing(
            new Element(HtmlTag::H2)->containing(MachineArea::Processes->value),
            $table,
        );
    }

    /**
     * The processes as lines, and each as data.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return [...(array) $this->text()->jsonSerialize(), ResultKey::Processes->value => $this->processes->toValues()];
    }

    /**
     * The processes as a section of text, one line each.
     *
     * @return HealthSection
     */
    private function text(): HealthSection
    {
        return $this->processes->isEmpty()
            ? HealthSection::lines(MachineArea::Processes->value, self::SILENT)
            : HealthSection::lines(
                MachineArea::Processes->value,
                ...$this->processes->map(static fn(MachineProcess $process): string => $process->line())->toValues(),
            );
    }
}
