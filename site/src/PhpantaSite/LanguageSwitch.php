<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use PhpantaSite\Text\SiteText;

/**
 * The page in each of the site's languages, each named in itself.
 *
 * Real links, so the switch works without JavaScript: to `rules.de.html` and `rules.en.html`, the
 * addresses that name their language. Each carries `hreflang`, which is what tells `Navigation` to
 * bring the other language's shell along, and what `LanguageChoice` remembers a click on; and `lang`,
 * so a screen reader says "Deutsch" in German.
 */
final class LanguageSwitch
{
    /**
     * The switch for $page.
     *
     * @param Page $page
     * @return Element
     */
    public static function for(Page $page): Element
    {
        $links = [];

        foreach (Site::current()->languages()->offered() as $language) {
            $links[] = new Element(HtmlTag::A)
                ->attr(HtmlAttribute::Href, $page->path()->inLanguage($language))
                ->attr(HtmlAttribute::HrefLang, $language)
                ->attr(HtmlAttribute::Lang, $language)
                ->containing($language->endonym());
        }

        return new Element(HtmlTag::Nav)
            ->attr(HtmlAttribute::ClassName, 'language-switch')
            ->attr(HtmlAttribute::AriaLabel, SiteText::Languages)
            ->containing(...$links);
    }
}
