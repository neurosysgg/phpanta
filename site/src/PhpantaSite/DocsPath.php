<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\Support\FillsPlaceholders;
use Phpanta\Support\Path;

/**
 * Every address the site answers on. A link is `DocsPath::X->to()`, never a string — the same cases
 * the routes are built on, so a link cannot name a page that does not exist.
 */
enum DocsPath: string implements Path
{
    use FillsPlaceholders;

    case Home           = '/';
    case GettingStarted = '/getting-started';
    case Rules          = '/rules';
    case Architecture   = '/architecture';
}
