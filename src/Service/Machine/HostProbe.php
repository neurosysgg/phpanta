<?php

declare(strict_types=1);

namespace Phpanta\Service\Machine;

use Phpanta\Model\Machine\MachineConfig;

/**
 * The HostProbe class. Which {@link MachineProbe} asks this machine: the Linux one where there is a
 * `/proc` to read, the portable one everywhere else.
 */
final class HostProbe
{
    /**
     * The probe for the machine this runs on.
     *
     * @param MachineConfig $config What the service may reach — its roots are the disks a machine
     *                              with no mount table to read is asked about.
     * @return MachineProbe
     */
    public static function for(MachineConfig $config): MachineProbe
    {
        return PHP_OS_FAMILY === 'Linux' && is_readable('/proc/stat')
            ? new LinuxProbe()
            : new PortableProbe($config);
    }
}
