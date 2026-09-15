<?php

declare(strict_types=1);

namespace Phpanta\Service\Machine;

use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Machine\MachineCounters;
use Phpanta\Model\Machine\MachineProcess;
use Phpanta\Support\Collection;

/**
 * The MachineProbe interface. What a machine says about itself, asked the way that machine answers.
 *
 * **One per kind of machine.** {@link LinuxProbe} reads `/proc` and `/sys`, which is where a Linux
 * machine keeps nearly everything worth knowing, and runs nothing to find it out. {@link PortableProbe}
 * asks only what PHP can ask of any machine — its name, its system, its load, its disks, its
 * addresses — and is what every other machine gets. {@link HostProbe::for()} picks.
 */
interface MachineProbe
{
    /**
     * What the machine is, as sections of facts — its host, its hardware, its memory, its disks, its
     * network, its sensors — leaving out a section it has nothing to say in.
     *
     * @return Collection<HealthSection>
     */
    public function sections(): Collection;

    /**
     * Its raw numbers at this moment.
     *
     * @return MachineCounters
     */
    public function counters(): MachineCounters;

    /**
     * What it is running, the processes holding the most memory first — none where it cannot say.
     *
     * @return Collection<MachineProcess>
     */
    public function processes(): Collection;
}
