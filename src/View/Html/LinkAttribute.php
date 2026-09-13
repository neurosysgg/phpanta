<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The LinkAttribute enum. The attributes this site puts on an `<a>` that change how it behaves.
 *
 * Only one so far, and it is load-bearing: without `data-no-spa` the SPA router fetches a download
 * link, gets the 303 and swallows it, and downloads silently stop working while every page still
 * looks fine. `Navigation.ts` reads the name, `assets/ts/model/LinkAttribute.ts` mirrors it, and the
 * parity test is what stops the two drifting.
 */
enum LinkAttribute: string implements AttributeName
{
    /** Bypasses the SPA router, so the browser performs a real navigation. */
    case NoSpa = 'data-no-spa';

    /**
     * @return string
     */
    public function attribute(): string
    {
        return $this->value;
    }

    /**
     * A flag the SPA router reads, not an address.
     *
     * @return bool
     */
    public function isUrl(): bool
    {
        return false;
    }
}
