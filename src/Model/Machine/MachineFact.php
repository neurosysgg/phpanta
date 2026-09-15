<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

/**
 * The MachineFact enum. The names the `machine` service's facts are written under, whichever probe or
 * section writes them.
 */
enum MachineFact: string
{
    case Hostname     = 'hostname';
    case System       = 'system';
    case Kernel       = 'kernel';
    case Architecture = 'architecture';
    case Uptime       = 'uptime';
    case Booted       = 'booted';
    case User         = 'user';
    case Model        = 'model';
    case Board        = 'board';
    case Processor    = 'processor';
    case Cores        = 'cores';
    case Frequency    = 'frequency';
    case Graphics     = 'graphics';
    case Memory       = 'memory';
    case Swap         = 'swap';
    case Load         = 'load';
    case Processes    = 'processes';
    case Path         = 'path';
    case Size         = 'size';
    case Modified     = 'modified';
    case Permissions  = 'permissions';
    case Owner        = 'owner';
    case Type         = 'type';
    case Target       = 'target';
    case Command      = 'command';
    case Directory    = 'directory';
    case Exit         = 'exit';
    case Took         = 'took';
}
