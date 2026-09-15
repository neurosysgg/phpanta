<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

/**
 * The MachineCounter enum. The raw numbers `machine v1 system` answers as data, under these keys.
 *
 * Whole numbers, every one, and cumulative where the machine counts that way — processor time since
 * boot, bytes since an interface came up — so a reader works out a rate from two of them rather than
 * trusting one the server sampled. Mirrored by `assets/ts/model/MachineCounter.ts`, which is how
 * `<machine-stats>` finds each.
 */
enum MachineCounter: string
{
    /** Processor time spent on anything but idling, in the kernel's ticks, since boot. */
    case CpuBusy = 'cpu-busy';

    /** Processor time altogether, in the same ticks. */
    case CpuTotal = 'cpu-total';

    /** Memory in use, in bytes: all of it less what the kernel says is available. */
    case MemoryUsed = 'memory-used';

    /** Memory altogether, in bytes. */
    case MemoryTotal = 'memory-total';

    /** Swap in use, in bytes. */
    case SwapUsed = 'swap-used';

    /** Swap altogether, in bytes. */
    case SwapTotal = 'swap-total';

    /** Bytes every interface but loopback has taken in. */
    case Received = 'received';

    /** Bytes every interface but loopback has sent. */
    case Sent = 'sent';

    /** The load averaged over the last minute, in hundredths. */
    case Load = 'load';

    /** When these were read, in milliseconds since the epoch. */
    case Time = 'time';
}
