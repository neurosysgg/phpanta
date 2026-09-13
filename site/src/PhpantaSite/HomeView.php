<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\Text\Translatable;
use Phpanta\View\Html\Fragment;
use Phpanta\View\Html\Node;
use Phpanta\View\Html\Sentence;
use PhpantaSite\Text\HomeText;
use PhpantaSite\Text\SiteText;

/**
 * The home page: what Phpanta is, what it is made of, and where to go next.
 */
final class HomeView extends ProseView
{
    /**
     * @return Page
     */
    public function page(): Page
    {
        return Page::Home;
    }

    /**
     * @return Fragment
     */
    protected function body(): Fragment
    {
        return new Fragment(
            Prose::title(Site::NAME),
            Prose::lede(HomeText::Lede),
            Prose::paragraph(new Sentence(HomeText::Extracted, site: Prose::link('https://neurosys.gg', 'neuro.SYS'))),
            Prose::heading(HomeText::MadeOf),
            Prose::items(
                self::point(HomeText::TypedHttpLead, HomeText::TypedHttp, request: Prose::code('Request')),
                self::point(HomeText::MarkupTreeLead, HomeText::MarkupTree, render: Prose::code('render()')),
                self::point(HomeText::CollectionsLead, HomeText::Collections),
                self::point(
                    HomeText::TranslationLead,
                    HomeText::Translation,
                    translatable: Prose::code('Translatable'),
                    lang: Prose::code('lang'),
                ),
                self::point(
                    HomeText::SignedApiLead,
                    HomeText::SignedApi,
                    address: Prose::code('/api/{service}/{version}/{action}'),
                ),
                self::point(HomeText::HealthLead, HomeText::Health),
                self::point(HomeText::NavigationLead, HomeText::Navigation, href: Prose::code('href')),
            ),
            Prose::heading(HomeText::Next),
            Prose::items(
                new Sentence(
                    HomeText::NextGettingStarted,
                    link: Prose::link(DocsPath::GettingStarted->inEachLanguage(), SiteText::GettingStarted),
                ),
                new Sentence(
                    HomeText::NextRules,
                    link: Prose::link(DocsPath::Rules->inEachLanguage(), SiteText::Rules),
                ),
                new Sentence(
                    HomeText::NextArchitecture,
                    link: Prose::link(DocsPath::Architecture->inEachLanguage(), SiteText::Architecture),
                ),
                new Sentence(
                    HomeText::NextRepository,
                    link: Prose::link(Site::REPOSITORY, HomeText::Repository),
                    docs: Prose::code('docs/'),
                ),
            ),
        );
    }

    /**
     * One point of what Phpanta is made of: a lead-in in bold, then the sentence it opens.
     *
     * @param Translatable $lead
     * @param Translatable $text
     * @param Node ...$parts The sentence's own parts, by name.
     * @return Sentence
     */
    private static function point(Translatable $lead, Translatable $text, Node ...$parts): Sentence
    {
        return new Sentence($text, ...$parts, lead: Prose::strong($lead));
    }
}
