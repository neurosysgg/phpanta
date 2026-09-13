<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\View\Html\TagName;

/**
 * The tags prose needs that the framework's standard vocabulary does not have: headings, code, and
 * a numbered list. Added to the vocabulary by {@link Site::vocabulary()}, which is what lets a page
 * under `data/` use them.
 */
enum ProseTag: string implements TagName
{
    case H1   = 'h1';
    case H2   = 'h2';
    case H3   = 'h3';
    case Pre  = 'pre';
    case Code = 'code';
    case Ol   = 'ol';

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
