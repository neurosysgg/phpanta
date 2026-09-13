<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\View\Html\TagName;

/**
 * The two tags prose needs that the framework's `HtmlTag` does not have: a code sample, and code.
 *
 * Only tags: the site parses nothing, so they are never added to its vocabulary — a view builds
 * them, and `render()` writes them like any other.
 */
enum ProseTag: string implements TagName
{
    case Pre  = 'pre';
    case Code = 'code';

    /**
     * @return string
     */
    public function tagName(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function isVoid(): bool
    {
        return false;
    }
}
