<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The ElementId enum. The id attributes the site assigns.
 *
 * One, and it is the hinge the SPA turns on: `Layout` puts it on the `<main>` and
 * `Navigation.forDocument()` looks it up to decide whether to intercept links at all. Rename one
 * side and the lookup returns null, navigation quietly switches itself off, and every page still
 * works — which is precisely why it needs naming rather than trusting.
 */
enum ElementId: string
{
    /** The `<main>` the SPA router swaps. */
    case Content = 'content';
}
