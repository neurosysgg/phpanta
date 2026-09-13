<?php

declare(strict_types=1);

namespace Phpanta\Text;

/**
 * The FrameworkText enum. The few words the framework says itself, whatever site it runs.
 *
 * Only what framework code sends without a site's view around it — a plain-text body the router or
 * the API controller writes before any page is involved. Everything a page says is the site's, in
 * the site's own catalogs; this is the one catalog a site inherits rather than writes, which is why
 * it holds so little.
 */
enum FrameworkText: string implements Translatable
{
    use Translated;

    /** The body of every 405, whichever of the two paths sent it — see UnroutedController. */
    #[Translation(en: 'This site is read-only.', de: 'Diese Seite ist schreibgeschützt.')]
    case ReadOnly = 'read-only';
}
