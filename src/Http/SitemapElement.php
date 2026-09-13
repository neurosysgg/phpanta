<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The SitemapElement enum. The three names a sitemap is written in, and the namespace they live in.
 *
 * Named rather than spelled at the call site for the reason every name here is: `url` in particular
 * is a word other vocabularies spell too, and a literal is a word nothing checks.
 */
enum SitemapElement: string
{
    /** The document's root: every address the site lists. */
    case UrlSet = 'urlset';

    /** One address. */
    case Url = 'url';

    /** Where that address is — absolute, origin included. */
    case Location = 'loc';

    /**
     * The sitemaps.org namespace every element is written in; a sitemap in any other is not one.
     *
     * @return string
     */
    public static function namespace(): string
    {
        return 'http://www.sitemaps.org/schemas/sitemap/0.9';
    }
}
