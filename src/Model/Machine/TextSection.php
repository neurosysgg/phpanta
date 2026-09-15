<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use Phpanta\Http\Api\ResultSection;
use Phpanta\Model\Health\HealthSection;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;

/**
 * The TextSection class. Text whose lines are its own — what a command printed — under a caption.
 *
 * A {@link HealthSection} of lines on a terminal and in the data, and a `<pre>` on a page, where a
 * list of lines would lose what the spaces in them meant.
 */
final readonly class TextSection implements ResultSection
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $caption
     * @param string $text
     */
    public function __construct(private string $caption, private string $text) {}

    /**
     * @return string
     */
    public function render(): string
    {
        return $this->lines()->render();
    }

    /**
     * @return Node
     */
    public function node(): Node
    {
        return new Element(HtmlTag::Section)->containing(
            new Element(HtmlTag::H2)->containing($this->caption),
            new Element(HtmlTag::Pre)->containing($this->text),
        );
    }

    /**
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return $this->lines()->jsonSerialize();
    }

    /**
     * The text as a section of lines.
     *
     * @return HealthSection
     */
    private function lines(): HealthSection
    {
        return HealthSection::lines($this->caption, ...explode("\n", rtrim($this->text, "\n")));
    }
}
