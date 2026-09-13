<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The Doctype enum. The document type declaration a page opens with.
 *
 * One case, and it earns its file: the doctype is what switches the browser between standards and
 * quirks mode, so a typo in it does not break the page — it silently re-does every layout
 * calculation on the page under 1990s rules. That is the quietest failure in the whole document,
 * which makes it the last string that should have been left as a string.
 */
enum Doctype: string implements Node
{
    /** HTML5. A second case would need a reason no modern page has. */
    case Html5 = 'html';

    /**
     * @param int                           $depth
     * @param \Phpanta\Text\Language|null $language Unread: a doctype has no words.
     * @return string
     */
    public function render(int $depth = 0, ?\Phpanta\Text\Language $language = null): string
    {
        return '<!DOCTYPE ' . $this->value . '>';
    }
}
