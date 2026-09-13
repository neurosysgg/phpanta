<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The RobotsDirective enum. The `X-Robots-Tag` rules this site states.
 *
 * Three of them, and the same rule {@link CacheDirective} follows: the enum is what the site says,
 * not a catalogue of what crawlers understand. Absent by decision is `noindex` for anything public
 * — the releases *want* indexing — so every case here exists for one page type.
 */
enum RobotsDirective: string
{
    /** Do not put this page in an index. */
    case NoIndex = 'noindex';

    /** Do not follow what it links to, which on a demo page includes the audio itself. */
    case NoFollow = 'nofollow';

    /**
     * Keep no cached copy to serve when the original is gone.
     *
     * The one that matters most for something unreleased: a demo's password can be rotated and its
     * files deleted, and neither undoes an archived copy that was taken while the page was open.
     */
    case NoArchive = 'noarchive';
}
