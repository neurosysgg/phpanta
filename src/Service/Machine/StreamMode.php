<?php

declare(strict_types=1);

namespace Phpanta\Service\Machine;

use Phpanta\Support\BareArray;

/**
 * The StreamMode enum. Which way a command's pipe runs, as `proc_open()` spells it.
 */
enum StreamMode: string
{
    /** A pipe the command reads from — its standard input. */
    case Read = 'r';

    /** A pipe the command writes to — its standard output, its standard error. */
    case Write = 'w';

    /**
     * The descriptor `proc_open()` takes for a pipe of this mode.
     *
     * @return list<string>
     */
    #[BareArray("proc_open()'s descriptor spec: a pair it reads as an array, and this is its door")]
    public function pipe(): array
    {
        return ['pipe', $this->value];
    }
}
