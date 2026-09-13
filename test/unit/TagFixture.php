<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\View\Html\TagName;

/**
 * A custom element for the tests: the shape a site's own tag enum takes, and the one the standard
 * vocabulary does not have.
 */
enum TagFixture: string implements TagName
{
    case Widget = 'x-widget';

    /**
     * @return string
     */
    public function tagName(): string
    {
        return $this->value;
    }

    /**
     * A custom element is never void.
     *
     * @return bool
     */
    public function isVoid(): bool
    {
        return false;
    }
}
