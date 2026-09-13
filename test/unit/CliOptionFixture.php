<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Tool\Cli\Option;

/**
 * A flag that takes no value, for {@link CliTest}'s stub command to declare beside
 * {@link \Phpanta\Tool\Command\MergeCoverageOption}'s two that take one.
 */
enum CliOptionFixture: string implements Option
{
    case Check = 'check';

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
        return false;
    }
}
