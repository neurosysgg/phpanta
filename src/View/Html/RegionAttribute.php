<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The RegionAttribute enum. The attributes that mark a part of the shell for the client.
 *
 * One so far: `data-language-bound`, on a part of the shell written in the page's language — a
 * header whose navigation names the pages, a footer that says what the site is — which a navigation
 * into another language replaces whole. A navigation inside one language swaps `#content` and
 * nothing else, so without the mark the header of a German page would go on reading English. It is
 * mirrored in TypeScript, where `Navigation` reads it.
 */
enum RegionAttribute: string implements AttributeName
{
    /** Written in the page's language, and so replaced by a navigation into another. */
    case LanguageBound = 'data-language-bound';

    /**
     * @return string
     */
    public function attribute(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function isUrl(): bool
    {
        return false;
    }
}
