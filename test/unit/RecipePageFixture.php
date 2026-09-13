<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Support\Collection;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;
use Phpanta\View\View;

/**
 * The login recipe's pages: a heading, the messages the session carried, and what the page holds.
 */
final class RecipePageFixture extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Translatable             $heading
     * @param Collection<Translatable> $messages What the session carried to this page, shown once.
     * @param Node                     $body
     */
    public function __construct(
        private readonly Translatable $heading,
        private readonly Collection $messages,
        private readonly Node $body,
    ) {}

    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title($this->heading);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Element(HtmlTag::Main)
            ->containing(new Element(HtmlTag::H1)->containing($this->heading))
            ->containing(...$this->messages
                ->map(static fn(Translatable $message): Node => new Element(HtmlTag::P)->containing($message))
                ->toValues())
            ->containing($this->body);
    }
}
