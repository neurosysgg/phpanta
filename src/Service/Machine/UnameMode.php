<?php

declare(strict_types=1);

namespace Phpanta\Service\Machine;

/**
 * The UnameMode enum. What `php_uname()` is asked for — its own one-letter vocabulary, named.
 */
enum UnameMode: string
{
    /** The operating system's name: `Linux`, `Darwin`, `Windows NT`. */
    case System = 's';

    /** Its release: the kernel's version. */
    case Release = 'r';

    /** The machine's architecture: `x86_64`, `aarch64`. */
    case Machine = 'm';

    /**
     * What `php_uname()` answers for this mode.
     *
     * @return string
     */
    public function asked(): string
    {
        return php_uname($this->value);
    }
}
