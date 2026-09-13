<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The Document class. A doctype and the `<html>` element under it.
 *
 * The doctype is the one piece of a page that is not an element and has no attributes, so it is
 * neither a {@link Tag} case nor an {@link Element}. It is a {@link Doctype}, which is a Node like
 * everything else here — leaving the last string in the document as a string would have been the
 * one exception, and it guards the loudest-consequence, quietest-failure value on the page.
 */
final readonly class Document implements Node
{
    /**
     * Constructs an instance of {@link self} wrapping the given `<html>` element.
     *
     * @param Element $html
     */
    public function __construct(private Element $html) {}

    /**
     * @param int                           $depth
     * @param \Phpanta\Text\Language|null $language Passed to `<html>`, whose own `lang` normally
     *                                                replaces it.
     * @return string
     */
    public function render(int $depth = 0, ?\Phpanta\Text\Language $language = null): string
    {
        return Doctype::Html5->render($depth)
            . "\n" . str_repeat('  ', $depth)
            . $this->html->render($depth, $language);
    }
}
