<?php

declare(strict_types=1);

namespace PhpantaSite;

use PhpantaSite\Text\SiteText;

/**
 * The site's pages, each one a markup tree its view builds, in each of the site's languages.
 *
 * There is no hand-authored HTML here any more: a page is a {@link ProseView}, and its words are a
 * catalog in `Text/`, one case per paragraph, with every piece of code and every link a node placed
 * into its sentence where each language's word order puts it. Every `h2` carries an anchor that is
 * its catalog case's key — `#five-habits` is `RulesText::FiveHabits` — so an anchor survives a
 * reworded heading and is the same in every language. `SitePagesTest` holds the convention, and the
 * export fails on a link to an id a page lacks.
 */
enum Page: string
{
    case Home           = 'home';
    case GettingStarted = 'getting-started';
    case Rules          = 'rules';
    case Architecture   = 'architecture';

    /**
     * Where the page is.
     *
     * @return DocsPath
     */
    public function path(): DocsPath
    {
        return match ($this) {
            self::Home           => DocsPath::Home,
            self::GettingStarted => DocsPath::GettingStarted,
            self::Rules          => DocsPath::Rules,
            self::Architecture   => DocsPath::Architecture,
        };
    }

    /**
     * What the navigation calls it, and what its title says in front of the site's name.
     *
     * @return SiteText
     */
    public function title(): SiteText
    {
        return match ($this) {
            self::Home           => SiteText::Home,
            self::GettingStarted => SiteText::GettingStarted,
            self::Rules          => SiteText::Rules,
            self::Architecture   => SiteText::Architecture,
        };
    }

    /**
     * The view that builds it.
     *
     * @return ProseView
     */
    public function view(): ProseView
    {
        return match ($this) {
            self::Home           => new HomeView(),
            self::GettingStarted => new GettingStartedView(),
            self::Rules          => new RulesView(),
            self::Architecture   => new ArchitectureView(),
        };
    }
}
