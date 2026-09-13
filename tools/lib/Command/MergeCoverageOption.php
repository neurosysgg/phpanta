<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Phpanta\Tool\Cli\Option;

/**
 * The MergeCoverageOption enum. The report formats `tools/merge-coverage.php` can write.
 *
 * Both take a path. They are cases rather than keys of a string-keyed array so that `Input` refuses
 * a mistyped `--clover` by name — a dropped flag would report success and write nothing.
 *
 * @see MergeCoverage
 */
enum MergeCoverageOption: string implements Option
{
    /** An XML file, for anything that reads Clover. */
    case Clover = 'clover';

    /** A directory of browsable HTML. */
    case Html = 'html';

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
        return true;
    }
}
