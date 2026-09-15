<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use JsonSerializable;

/**
 * The MachineCounters class. The machine's raw numbers at one moment — see {@link MachineCounter}.
 *
 * Every one is a whole number and none is required: a machine that cannot say one says zero, and
 * the reading worked out from it shows nothing rather than something made up.
 */
final readonly class MachineCounters implements JsonSerializable
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $cpuBusy     Processor ticks not idling, since boot.
     * @param int $cpuTotal    Processor ticks altogether.
     * @param int $memoryUsed  Bytes of memory in use.
     * @param int $memoryTotal Bytes of memory altogether.
     * @param int $swapUsed    Bytes of swap in use.
     * @param int $swapTotal   Bytes of swap altogether.
     * @param int $received    Bytes taken in by every interface but loopback.
     * @param int $sent        Bytes sent by them.
     * @param int $load        The last minute's load average, in hundredths.
     * @param int $time        When these were read, in milliseconds since the epoch.
     */
    public function __construct(
        public int $cpuBusy = 0,
        public int $cpuTotal = 0,
        public int $memoryUsed = 0,
        public int $memoryTotal = 0,
        public int $swapUsed = 0,
        public int $swapTotal = 0,
        public int $received = 0,
        public int $sent = 0,
        public int $load = 0,
        public int $time = 0,
    ) {}

    /**
     * The one counter $counter names.
     *
     * @param MachineCounter $counter
     * @return int
     */
    public function of(MachineCounter $counter): int
    {
        return match ($counter) {
            MachineCounter::CpuBusy     => $this->cpuBusy,
            MachineCounter::CpuTotal    => $this->cpuTotal,
            MachineCounter::MemoryUsed  => $this->memoryUsed,
            MachineCounter::MemoryTotal => $this->memoryTotal,
            MachineCounter::SwapUsed    => $this->swapUsed,
            MachineCounter::SwapTotal   => $this->swapTotal,
            MachineCounter::Received    => $this->received,
            MachineCounter::Sent        => $this->sent,
            MachineCounter::Load        => $this->load,
            MachineCounter::Time        => $this->time,
        };
    }

    /**
     * Every counter under its key.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        $counters = [];

        foreach (MachineCounter::cases() as $counter) {
            $counters[$counter->value] = $this->of($counter);
        }

        return $counters;
    }
}
