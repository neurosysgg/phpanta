<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The CacheDirective enum. The `Cache-Control` directives this site sends.
 *
 * Only the three it actually uses, which is the same rule {@link Security\PermissionsPolicyFeature}
 * follows: the enum is the list of things the site says, not a catalogue of what exists. Notably
 * absent is `max-age`, and not by oversight — it carries a number, so it could not be a case, and
 * nothing here sends one anyway. Freshness lifetimes on this site belong to `public/.htaccess`,
 * where they are attached to file extensions; a document answers with a validator instead.
 */
enum CacheDirective: string
{
    /**
     * Keep the copy, but revalidate before reusing it.
     *
     * The most misread directive in HTTP: it is not `no-store`. A `no-cache` response *is* written
     * to the cache — it simply may not be served from there without asking, which is what makes the
     * 304 in {@link ViewResponse} worth having.
     */
    case NoCache = 'no-cache';

    /** Do not write this response down at all. */
    case NoStore = 'no-store';

    /** For one browser's cache, never a shared one in between. */
    case Private = 'private';
}
