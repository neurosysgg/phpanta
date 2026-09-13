<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;
use Phpanta\View\View;

/**
 * A page of prose: its hand-authored HTML, parsed against the site's vocabulary.
 */
final class PageView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Page   $page
     * @param string $html The page's body, as `data/` holds it.
     */
    public function __construct(
        private readonly Page $page,
        private readonly string $html,
    ) {}

    /**
     * The site's name alone on the home page; the page's title in front of it everywhere else.
     *
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return $this->page === Page::Home ? self::title() : self::title($this->page->title());
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Element(HtmlTag::Section)->containingHtml($this->html);
    }
}
