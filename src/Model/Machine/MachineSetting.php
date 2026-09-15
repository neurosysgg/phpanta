<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

/**
 * The MachineSetting enum. The keys `data/machine.json` is written with.
 *
 * ```json
 * { "roots": ["/"], "writes": true, "commands": false }
 * ```
 *
 * See {@link MachineConfig} for what each means and what an absent or mistyped one reads as.
 */
enum MachineSetting: string
{
    /** The directories the service may walk, each absolute; everything under one is reachable. */
    case Roots = 'roots';

    /** Whether it may keep, make, rename and remove there. False where absent. */
    case Writes = 'writes';

    /** Whether it may run a command. False where absent, and never without writes. */
    case Commands = 'commands';

    /**
     * Where an app whose only job is this service is served from, for the admin's passkeys — see
     * {@link \Phpanta\App::origin()}. The service itself never reads it.
     */
    case Origin = 'origin';
}
