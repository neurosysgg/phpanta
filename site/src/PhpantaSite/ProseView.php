<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\Fragment;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;
use Phpanta\View\View;

/**
 * A page of prose: its language switch, then what it says.
 *
 * The switch is part of the page rather than of the shell, because it names *this* page's other
 * language — in the shell, a navigation within one language, which swaps only `#content`, would
 * leave it pointing at the page before.
 */
abstract class ProseView extends View
{
    /**
     * Which page this is.
     *
     * @return Page
     */
    abstract public function page(): Page;

    /**
     * What the page says, heading first.
     *
     * @return Fragment
     */
    abstract protected function body(): Fragment;

    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return $this->page() === Page::Home ? self::title() : self::title($this->page()->title());
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Element(HtmlTag::Section)->containing(LanguageSwitch::for($this->page()), $this->body());
    }
}
