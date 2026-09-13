<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\View\Html\Fragment;
use Phpanta\View\Html\Sentence;
use PhpantaSite\Text\RulesText;
use PhpantaSite\Text\SiteText;

/**
 * The rules: each habit that fails silently, and the one that replaces it.
 */
final class RulesView extends ProseView
{
    /**
     * @return Page
     */
    public function page(): Page
    {
        return Page::Rules;
    }

    /**
     * @return Fragment
     */
    protected function body(): Fragment
    {
        return new Fragment(
            Prose::title(SiteText::Rules),
            Prose::lede(RulesText::Lede),
            Prose::heading(RulesText::NoHtmlFromStrings),
            Prose::paragraph(new Sentence(
                RulesText::NoHtmlFromStringsText,
                node: Prose::code('Node'),
                element: Prose::code('Element'),
                doctype: Prose::code('Doctype'),
                bracket: Prose::code('<'),
                render: Prose::code('render()'),
                javascript: Prose::code('javascript:'),
            )),
            Prose::heading(RulesText::ParsedNotTrusted),
            Prose::paragraph(new Sentence(
                RulesText::ParsedNotTrustedText,
                containingHtml: Prose::code('Element::containingHtml()'),
            )),
            Prose::heading(RulesText::Translatable),
            Prose::paragraph(new Sentence(
                RulesText::TranslatableText,
                lang: Prose::code('lang'),
                sentence: Prose::code('Sentence'),
            )),
            Prose::heading(RulesText::Typed),
            Prose::paragraph(new Sentence(
                RulesText::TypedText,
                headerName: Prose::code('HeaderName'),
                headerValue: Prose::code('HeaderValue'),
                attributeName: Prose::code('AttributeName'),
                path: Prose::code('Path'),
                to: Prose::code('->to(…)'),
            )),
            Prose::heading(RulesText::Collections),
            Prose::paragraph(new Sentence(
                RulesText::CollectionsText,
                collection: Prose::code('Collection'),
                searchable: Prose::code('SearchableCollection'),
                with: Prose::code('with()'),
            )),
            Prose::heading(RulesText::FiveHabits),
            Prose::items(
                new Sentence(RulesText::BareArray, array: Prose::code('array')),
                RulesText::BareString,
                new Sentence(RulesText::BareCall, call: Prose::code('array_*')),
                new Sentence(RulesText::Suppression, at: Prose::code('@')),
                RulesText::SplException,
            ),
            Prose::paragraph(new Sentence(
                RulesText::Excused,
                bareArray: Prose::code('#[BareArray]'),
                bareString: Prose::code('#[BareString]'),
                bareCall: Prose::code('#[BareCall]'),
            )),
            Prose::heading(RulesText::NoDroppedResults),
            Prose::paragraph(new Sentence(RulesText::NoDroppedResultsText, noDiscard: Prose::code('#[\NoDiscard]'))),
            Prose::heading(RulesText::EveryDecisionReturns),
            Prose::paragraph(new Sentence(
                RulesText::EveryDecisionReturnsText,
                answer: Prose::code('answer()'),
                answerClass: Prose::code('Answer'),
                handle: Prose::code('App::handle()'),
                run: Prose::code('App::run()'),
                noDiscard: Prose::code('#[\NoDiscard]'),
                exit: Prose::code('exit'),
                testRequest: Prose::code('Phpanta\Test\TestRequest'),
            )),
            Prose::paragraph(new Sentence(
                RulesText::FullArgument,
                guidelines: Prose::link(Site::REPOSITORY . '/blob/master/docs/guidelines.md', 'docs/guidelines.md'),
                claude: Prose::link(Site::REPOSITORY . '/blob/master/CLAUDE.md', 'CLAUDE.md'),
            )),
        );
    }
}
