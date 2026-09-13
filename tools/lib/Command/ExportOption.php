<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Phpanta\Tool\Cli\Option;

/**
 * The ExportOption enum. The flags `export` accepts.
 *
 * Declared so {@link \Phpanta\Tool\Cli\Input} refuses one this command never named: a mistyped
 * `--base` that were dropped would write a site whose every address 404s on the host it was for.
 */
enum ExportOption: string implements Option
{
    /** Where the static site is written. Required, and emptied first only if an export wrote it. */
    case Out = 'out';

    /** The path it is served under: `/` by default, `/phpanta/` for a GitHub project page. */
    case Base = 'base';

    /**
     * Export the debug tree — `public/` as `npm run build` leaves it, one module per file — rather
     * than the prod tree in `build/dist/`. For looking at an export locally; not for shipping one.
     */
    case Debug = 'debug';

    /**
     * @return string
     */
    public function flag(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function takesValue(): bool
    {
        return $this !== self::Debug;
    }
}
