<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\View\Html\TagName;

/**
 * The tag prose needs that the framework's `HtmlTag` does not have: code, inline. A whole sample is
 * a {@link CodeTag::Block}.
 *
 * Only a tag: the site parses nothing, so it is never added to its vocabulary — a view builds it,
 * and `render()` writes it like any other.
 */
enum ProseTag: string implements TagName
{
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
