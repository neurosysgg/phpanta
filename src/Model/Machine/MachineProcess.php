<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use JsonSerializable;
use Phpanta\Http\Api\ResultKey;

/**
 * The MachineProcess class. One process the machine is running: who runs it, what it holds, how much
 * processor time it has had, and its command line.
 */
final readonly class MachineProcess implements JsonSerializable
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int    $pid     Its id.
     * @param string $owner   Who runs it, by name where the machine can say.
     * @param string $state   The kernel's one letter for what it is doing.
     * @param int    $memory  The memory it holds, in bytes.
     * @param int    $cpu     The processor time it has had, in seconds.
     * @param int    $started When it started.
     * @param string $command Its command line, or its name in brackets where it has none — a kernel thread.
     */
    public function __construct(
        public int    $pid,
        public string $owner,
        public string $state,
        public int    $memory,
        public int    $cpu,
        public int    $started,
        public string $command,
    ) {}

    /**
     * The process as a line of a table: id, owner, state, memory, processor time, start, command.
     *
     * @return string
     */
    public function line(): string
    {
        return sprintf(
            '%7d  %-10s %s %10s %10s  %s  %s',
            $this->pid,
            $this->owner,
            $this->state,
            Measure::bytes($this->memory),
            Measure::duration($this->cpu),
            Measure::moment($this->started),
            $this->command,
        );
    }

    /**
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return [
            ResultKey::Pid->value     => $this->pid,
            ResultKey::Owner->value   => $this->owner,
            ResultKey::State->value   => $this->state,
            ResultKey::Memory->value  => $this->memory,
            ResultKey::Cpu->value     => $this->cpu,
            ResultKey::Started->value => date(DATE_ATOM, $this->started),
            ResultKey::Command->value => $this->command,
        ];
    }
}
