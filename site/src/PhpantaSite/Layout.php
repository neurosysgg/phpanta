<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\Support\Charset;
use Phpanta\Text\Language;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Document;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\ElementId;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\LinkRel;
use Phpanta\View\Html\MetaName;
use Phpanta\View\Html\ScriptType;
use Phpanta\View\Html\ViewportContent;
use Phpanta\View\Html\ViewportWidth;
use Phpanta\View\Shell;
use Phpanta\View\View;

/**
 * The shell every page is rendered inside: the head, the navigation, the page, the footer.
 */
final class Layout implements Shell
{
    /** What a search result or a shared link says about the site. */
    private const string DESCRIPTION = 'Phpanta — a small full-stack web framework for plain PHP 8.5 and '
        . 'browser-native TypeScript.';

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
                    self::head($view->pageTitle()),
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
     * @param Translatable $title
     * @return Element
     */
    private static function head(Translatable $title): Element
    {
        $preloads = [];

        foreach (AssetManifest::MODULES as $module) {
            $preloads[] = new Element(HtmlTag::Link)
                ->attr(HtmlAttribute::Rel, LinkRel::ModulePreload)
                ->attr(HtmlAttribute::Href, $module);
        }

        return new Element(HtmlTag::Head)->containing(
            new Element(HtmlTag::Meta)->attr(HtmlAttribute::Charset, Charset::Utf8->canonical()),
            new Element(HtmlTag::Meta)
                ->attr(HtmlAttribute::Name, MetaName::Viewport)
                ->attr(HtmlAttribute::Content, new ViewportContent(width: ViewportWidth::Device, initialScale: 1.0)),
            new Element(HtmlTag::Title)->containing($title),
            new Element(HtmlTag::Meta)
                ->attr(HtmlAttribute::Name, MetaName::Description)
                ->attr(HtmlAttribute::Content, self::DESCRIPTION),
            new Element(HtmlTag::Link)
                ->attr(HtmlAttribute::Rel, LinkRel::Stylesheet)
                ->attr(HtmlAttribute::Href, AssetManifest::STYLESHEET),
            ...$preloads,
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
                ->attr(HtmlAttribute::Href, $page->path()->to())
                ->containing($page->title());
        }

        $links[] = new Element(HtmlTag::A)->attr(HtmlAttribute::Href, Site::REPOSITORY)->containing('GitHub');

        return new Element(HtmlTag::Header)
            ->attr(HtmlAttribute::ClassName, 'site-header')
            ->containing(
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::ClassName, 'wordmark')
                    ->attr(HtmlAttribute::Href, DocsPath::Home->to())
                    ->containing(Site::NAME),
                new Element(HtmlTag::Nav)
                    ->attr(HtmlAttribute::ClassName, 'site-nav')
                    ->attr(HtmlAttribute::AriaLabel, 'Pages')
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
            ->containing(
                new Element(HtmlTag::P)->containing(
                    'Phpanta is MIT-licensed. This site is built with it and exported to static files; ',
                    new Element(HtmlTag::A)
                        ->attr(HtmlAttribute::Href, Site::REPOSITORY . '/tree/master/site')
                        ->containing('its source'),
                    ' is part of the repository.',
                ),
            );
    }
}
