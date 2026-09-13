<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The AcceptRanges enum. Whether a response may be asked for in pieces, and in what unit.
 *
 * A two-case enum for the same reason {@link Security\ContentTypeOptions} is a one-case one: the
 * header's vocabulary is closed, so it may as well be the type. `bytes` is the only unit anyone
 * registered; `none` is the way to say ranges are not supported, which is different from omitting
 * the header — omitting it leaves a client to try and find out.
 *
 * It matters here because an `<audio>` element reads it before it will let anyone drag the
 * scrubber. A ranged response that never advertises itself is a player that plays and will not
 * seek, which looks like a broken control rather than a missing header.
 */
enum AcceptRanges: string implements HeaderValue
{
    /** Ranges are supported, counted in bytes. The only unit there is. */
    case Bytes = 'bytes';

    /** Ranges are not supported — said out loud, rather than left to be discovered. */
    case None = 'none';

    /**
     * @return string
     */
    public function render(): string
    {
        return $this->value;
    }
}
