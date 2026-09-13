<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Exception\SecurityPolicyException;

/**
 * The ContentLength class. How many bytes the body is.
 *
 * The thinnest {@link HeaderValue} here, and it exists for a mechanical reason rather than a
 * grammatical one: {@link Header} takes a `HeaderValue`, so a bare int has nowhere to go. What it
 * adds beyond that is the one check worth making — a negative length is not a header, and the way
 * to get one is arithmetic on a range that went wrong, which is precisely the code this ships
 * alongside.
 *
 * The site's other responses do not send this at all; PHP works it out from what is echoed. A
 * ranged response has to say it explicitly, because the number is the length of the *part* and not
 * of the file.
 */
final readonly class ContentLength implements HeaderValue
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $bytes
     *
     * @throws SecurityPolicyException if $bytes is negative.
     */
    public function __construct(private int $bytes)
    {
        if ($bytes < 0) {
            throw new SecurityPolicyException(sprintf(
                'ContentLength cannot be negative, got %d — check the range arithmetic above this.',
                $bytes,
            ));
        }
    }

    /**
     * Returns the header value: the count, in decimal, with no unit.
     *
     * @return string
     */
    public function render(): string
    {
        return (string) $this->bytes;
    }
}
