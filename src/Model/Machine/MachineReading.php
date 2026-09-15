<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

/**
 * The MachineReading enum. What `<machine-stats>` shows, one meter and one value each.
 *
 * A reading is worked out from {@link MachineCounter}s — a processor's busyness is the change in two
 * counters between two answers — which is why the client works them out: the server answers one
 * moment, and only the page holds the one before it. Mirrored by
 * `assets/ts/model/MachineReading.ts`.
 */
enum MachineReading: string
{
    /** How busy the processors were since the last answer, in percent. */
    case Cpu = 'cpu';

    /** How much memory is in use, in percent of all of it. */
    case Memory = 'memory';

    /** How much swap is in use, in percent of all of it. */
    case Swap = 'swap';

    /** How fast the network took bytes in since the last answer. */
    case Received = 'received';

    /** How fast it sent them. */
    case Sent = 'sent';

    /** The load averaged over the last minute. */
    case Load = 'load';
}
