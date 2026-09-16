<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The DropTag enum. The custom elements the page at `/drop` writes.
 *
 * Each works without its script: the page asks for the token, and the element only fills it in from
 * the link. A site that serves drops imports the module that defines it from its entry script — see
 * `assets/ts/elements/DropReveal.ts` — and `assets/ts/model/DropTag.ts` mirrors this list.
 */
enum DropTag: string implements TagName
{
    /**
     * Around the token's field: fills it in from the link's `#`, hides it, and takes the token out of
     * the address, so it is not left in the browser's history.
     */
    case Reveal = 'drop-reveal';

    /**
     * @return string
     */
    public function tagName(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function isVoid(): bool
    {
        return false;
    }
}
