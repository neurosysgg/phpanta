<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use Phpanta\Http\Api\ResultSection;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;

/**
 * The LinkSection class. Where to go from here — back to the directory a write changed.
 *
 * A link on a page, and its address as a line on a terminal and in the data.
 */
final readonly class LinkSection implements ResultSection
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Translatable $label What the link says.
     * @param string       $href  Where it leads.
     */
    public function __construct(private Translatable $label, private string $href) {}

    /**
     * @return string
     */
    public function render(): string
    {
        return $this->href;
    }

    /**
     * @return Node
     */
    public function node(): Node
    {
        return new Element(HtmlTag::P)->containing(
            new Element(HtmlTag::A)->attr(HtmlAttribute::Href, $this->href)->containing($this->label),
        );
    }

    /**
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return HealthSection::lines(null, $this->href)->jsonSerialize();
    }
}
