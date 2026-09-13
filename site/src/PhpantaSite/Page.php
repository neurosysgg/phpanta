<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\DataFileName;

/**
 * The site's pages, each one a file of hand-authored HTML under `data/`.
 *
 * Prose is written as HTML rather than built as a tree, because it is prose; it enters the tree
 * through `Element::containingHtml()`, which parses it against the site's vocabulary and refuses
 * anything else — so a page that uses a tag the site does not know fails to render rather than
 * shipping it.
 */
enum Page: string implements DataFileName
{
    case Home           = 'home.html';
    case GettingStarted = 'getting-started.html';
    case Rules          = 'rules.html';
    case Architecture   = 'architecture.html';

    /**
     * Every page is part of the repository.
     *
     * @return bool
     */
    public function isTracked(): bool
    {
        return true;
    }

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
     * @return string
     */
    public function title(): string
    {
        return match ($this) {
            self::Home           => 'Home',
            self::GettingStarted => 'Getting started',
            self::Rules          => 'The rules',
            self::Architecture   => 'Architecture',
        };
    }
}
