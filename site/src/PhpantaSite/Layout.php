<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\Support\Charset;
use Phpanta\Text\Language;
use Phpanta\View\Html\Document;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\ElementId;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\LinkRel;
use Phpanta\View\Html\MetaName;
use Phpanta\View\Html\RegionAttribute;
use Phpanta\View\Html\ScriptType;
use Phpanta\View\Html\Sentence;
use Phpanta\View\Html\ViewportContent;
use Phpanta\View\Html\ViewportWidth;
use Phpanta\View\Shell;
use Phpanta\View\View;
use PhpantaSite\Text\SiteText;

/**
 * The shell every page is rendered inside: the head, the navigation, the page, the footer.
 *
 * **The header and the footer are written in the page's language, and marked so**
 * (`data-language-bound`): a navigation within one language swaps only `#content`, and one into
 * another replaces these two as well, so the navigation never reads English on a German page. Their
 * links are built with `inEachLanguage()`, so the German page's header leads to German pages.
 *
 * **A page of prose states its address in each language in the head**, as
 * `<link rel="alternate" hreflang>`. That is what `LanguageChoice` reads on a static host to send a
 * visitor on a plain address to their language, and what a search engine reads to know the pages
 * are one page in two languages.
 */
final class Layout implements Shell
{
    /**
     * @param View     $view
     * @param Language $language
     * @return Document
     */
    public function document(View $view, Language $language): Document
    {
        return new Document(
            new Element(HtmlTag::Html)
                ->attr(HtmlAttribute::Lang, $language)
                ->containing(
                    self::head($view),
                    new Element(HtmlTag::Body)->containing(
                        self::header(),
                        new Element(HtmlTag::Main)
                            ->attr(HtmlAttribute::Id, ElementId::Content)
                            ->containing($view->content()),
                        self::footer(),
                        new Element(HtmlTag::Script)
                            ->attr(HtmlAttribute::Type, ScriptType::Module)
                            ->attr(HtmlAttribute::Src, AssetManifest::SCRIPT),
                    ),
                ),
        );
    }

    /**
     * @param View $view
     * @return Element
     */
    private static function head(View $view): Element
    {
        $links = [];

        // Only a page of prose has addresses in each language; the not-found page is at every
        // address there is.
        if ($view instanceof ProseView) {
            foreach (Site::current()->languages()->offered() as $language) {
                $links[] = new Element(HtmlTag::Link)
                    ->attr(HtmlAttribute::Rel, LinkRel::Alternate)
                    ->attr(HtmlAttribute::HrefLang, $language)
                    ->attr(HtmlAttribute::Href, $view->page()->path()->inLanguage($language));
            }
        }

        foreach (AssetManifest::MODULES as $module) {
            $links[] = new Element(HtmlTag::Link)
                ->attr(HtmlAttribute::Rel, LinkRel::ModulePreload)
                ->attr(HtmlAttribute::Href, $module);
        }

        return new Element(HtmlTag::Head)->containing(
            new Element(HtmlTag::Meta)->attr(HtmlAttribute::Charset, Charset::Utf8->canonical()),
            new Element(HtmlTag::Meta)
                ->attr(HtmlAttribute::Name, MetaName::Viewport)
                ->attr(HtmlAttribute::Content, new ViewportContent(width: ViewportWidth::Device, initialScale: 1.0)),
            new Element(HtmlTag::Title)->containing($view->pageTitle()),
            new Element(HtmlTag::Meta)
                ->attr(HtmlAttribute::Name, MetaName::Description)
                ->attr(HtmlAttribute::Content, SiteText::Description),
            new Element(HtmlTag::Link)
                ->attr(HtmlAttribute::Rel, LinkRel::Stylesheet)
                ->attr(HtmlAttribute::Href, AssetManifest::STYLESHEET),
            ...$links,
        );
    }

    /**
     * The wordmark, then every page but the home page, then the repository.
     *
     * @return Element
     */
    private static function header(): Element
    {
        $links = [];

        foreach ([Page::GettingStarted, Page::Rules, Page::Architecture] as $page) {
            $links[] = new Element(HtmlTag::A)
                ->attr(HtmlAttribute::Href, $page->path()->inEachLanguage())
                ->containing($page->title());
        }

        $links[] = new Element(HtmlTag::A)->attr(HtmlAttribute::Href, Site::REPOSITORY)->containing('GitHub');

        return new Element(HtmlTag::Header)
            ->attr(HtmlAttribute::ClassName, 'site-header')
            ->attr(RegionAttribute::LanguageBound)
            ->containing(
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::ClassName, 'wordmark')
                    ->attr(HtmlAttribute::Href, DocsPath::Home->inEachLanguage())
                    ->containing(Site::NAME),
                new Element(HtmlTag::Nav)
                    ->attr(HtmlAttribute::ClassName, 'site-nav')
                    ->attr(HtmlAttribute::AriaLabel, SiteText::Pages)
                    ->containing(...$links),
            );
    }

    /**
     * @return Element
     */
    private static function footer(): Element
    {
        return new Element(HtmlTag::Footer)
            ->attr(HtmlAttribute::ClassName, 'site-footer')
            ->attr(RegionAttribute::LanguageBound)
            ->containing(
                new Element(HtmlTag::P)->containing(new Sentence(
                    SiteText::Footer,
                    source: new Element(HtmlTag::A)
                        ->attr(HtmlAttribute::Href, Site::REPOSITORY . '/tree/master/site')
                        ->containing(SiteText::FooterSource),
                )),
            );
    }
}
