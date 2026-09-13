<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

use Phpanta\Http\HeaderValue;

/**
 * The ContentTypeOptions enum. The only value `X-Content-Type-Options` defines.
 *
 * A single-case enum on purpose: the header has exactly one legal value, so this makes that
 * fact the type rather than a comment next to a magic string.
 */
enum ContentTypeOptions: string implements HeaderValue
{
    /** Take the declared Content-Type at its word; never sniff the bytes. */
    case NoSniff = 'nosniff';

    /**
     * The only value this header defines, as the header carries it.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->value;
    }
}
