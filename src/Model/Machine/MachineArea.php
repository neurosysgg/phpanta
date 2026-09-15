<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

/**
 * The MachineArea enum. The captions the `machine` service's sections go under — one vocabulary for
 * the probes that write them and the tests that read them.
 */
enum MachineArea: string
{
    case Host      = 'host';
    case Hardware  = 'hardware';
    case Memory    = 'memory';
    case Load      = 'load';
    case Disks     = 'disks';
    case Network   = 'network';
    case Sensors   = 'sensors';
    case Battery   = 'battery';
    case Live      = 'live';
    case Processes = 'processes';
    case Roots     = 'roots';
    case Output    = 'output';
    case Errors    = 'errors';
}
