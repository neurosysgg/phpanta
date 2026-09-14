<?php

declare(strict_types=1);

namespace Hello;

use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;
use Phpanta\View\Html\Sentence;
use Phpanta\View\View;

/**
 * The page: a tree of nodes, never a string, so a name with markup in it is only ever text.
 */
final class Greeting extends View
{
    /** Whom the world page suggests greeting next. */
    private const string SOMEONE = 'Ada';

    /**
     * @param string|null $name
     */
    public function __construct(private ?string $name = null) {}

    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title($this->name);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        if ($this->name === null) {
            return new Element(HtmlTag::Main)->containing(
                new Element(HtmlTag::H1)->containing(HelloText::World),
                new Element(HtmlTag::P)->containing(new Sentence(
                    HelloText::GreetSomeone,
                    someone: new Element(HtmlTag::A)
                        ->attr(HtmlAttribute::Href, HelloPath::Someone->to(self::SOMEONE))
                        ->containing(self::SOMEONE),
                )),
            );
        }

        return new Element(HtmlTag::Main)->containing(
            new Element(HtmlTag::H1)->containing(HelloText::Someone->with(name: $this->name)),
            new Element(HtmlTag::P)->containing(HelloText::Letters->with(letters: mb_strlen($this->name))),
            new Element(HtmlTag::A)->attr(HtmlAttribute::Href, HelloPath::World->to())->containing(HelloText::Back),
        );
    }
}
