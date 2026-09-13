<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The RobotsDirective enum. The `X-Robots-Tag` rules the framework states.
 *
 * Three of them, and the same rule {@link CacheDirective} follows: the enum is what a site says,
 * not a catalogue of what crawlers understand. Absent by decision is `noindex` for anything public
 * — a public page *wants* indexing — so every case here exists for one kind of page: the gated one.
 */
enum RobotsDirective: string
{
    /** Do not put this page in an index. */
    case NoIndex = 'noindex';

    /** Do not follow what it links to, which on a gated page includes the files it guards. */
    case NoFollow = 'nofollow';

    /**
     * Keep no cached copy to serve when the original is gone.
     *
     * The one that matters most for something private: a gated page's password can be rotated and
     * its files deleted, and neither undoes an archived copy that was taken while the page was open.
     */
    case NoArchive = 'noarchive';
}
