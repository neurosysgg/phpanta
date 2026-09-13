<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Exception\SecurityPolicyException;

/**
 * The RetryAfter class. How long a client should wait before asking again, in seconds.
 *
 * **Delay-seconds, never an HTTP-date**, though the header allows either (RFC 9110 §10.2.3). A date
 * is only right where the server's clock and the client's agree, and a client whose clock runs an
 * hour fast reads a date an hour out as "now"; a number of seconds needs no clock but the one that
 * counts them. It is also what the framework has in hand: a {@link \Phpanta\Support\ThrottleVerdict}
 * says how long, not until when.
 *
 * The check is the one {@link ContentLength} makes, for the same reason: a negative wait is not a
 * header, and the way to get one is arithmetic on a window that went wrong.
 */
final readonly class RetryAfter implements HeaderValue
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $seconds
     *
     * @throws SecurityPolicyException if $seconds is negative.
     */
    public function __construct(private int $seconds)
    {
        if ($seconds < 0) {
            throw new SecurityPolicyException(sprintf(
                'RetryAfter cannot be negative, got %d — check the window arithmetic above this.',
                $seconds,
            ));
        }
    }

    /**
     * Returns the header value: the delay, in decimal seconds.
     *
     * @return string
     */
    public function render(): string
    {
        return (string) $this->seconds;
    }
}
