<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;
use Phpanta\View\Html\Sentence;
use Phpanta\View\View;
use PhpantaSite\Text\SiteText;

/**
 * What the site says about an address it does not have — which the export writes as `404.html`,
 * the page GitHub Pages serves for any address it does not have either. In the site's default
 * language, since it is at every address there is.
 */
final class NotFoundView extends View
{
    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title(SiteText::NotFound);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Element(HtmlTag::Section)->containing(
            new Element(HtmlTag::H1)->containing(SiteText::NotFound),
            new Element(HtmlTag::P)->containing(new Sentence(
                SiteText::NotFoundText,
                start: new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::Href, DocsPath::Home->inEachLanguage())
                    ->containing(SiteText::StartAtTheBeginning),
            )),
        );
    }
}
