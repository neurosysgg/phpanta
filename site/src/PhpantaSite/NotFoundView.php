<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;
use Phpanta\View\View;

/**
 * What the site says about an address it does not have — which the export writes as `404.html`,
 * the page GitHub Pages serves for any address it does not have either.
 */
final class NotFoundView extends View
{
    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title('Not found');
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Element(HtmlTag::Section)->containing(
            new Element(ProseTag::H1)->containing('Not found'),
            new Element(HtmlTag::P)->containing(
                'There is no page at this address. ',
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::Href, DocsPath::Home->to())
                    ->containing('Start at the beginning'),
                '.',
            ),
        );
    }
}
