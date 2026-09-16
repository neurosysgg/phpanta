<?php

declare(strict_types=1);

namespace Phpanta\View;

use Phpanta\Text\DropText;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;

/**
 * The DropTextView class. Text a drop held, revealed on the page that asked for it — escaped like any
 * other text, and kept by no cache.
 */
final class DropTextView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $text What the drop held.
     * @param bool   $once Whether it was meant to be read once, and so is gone now.
     */
    public function __construct(private readonly string $text, private readonly bool $once) {}

    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title(DropText::Heading);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Element(HtmlTag::Section)->containing(
            new Element(HtmlTag::H1)->containing(DropText::Heading),
            new Element(HtmlTag::P)->containing($this->once ? DropText::ReadOnce : DropText::Revealed),
            new Element(HtmlTag::Pre)->containing($this->text),
        );
    }
}
