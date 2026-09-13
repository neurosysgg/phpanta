<?php

declare(strict_types=1);

namespace Phpanta\Tool\Http;

use RuntimeException;

/**
 * The TransportException class. Thrown when a request never became a response.
 *
 * Distinct from a response that says no. A 401, a 422 and a 503 all arrived, and what to do about
 * each is the caller's decision — an API client a site builds on {@link Transport} turns those into
 * an exception of its own, with a message. This is the other thing: DNS, TLS, a connection that
 * died halfway through a large body. Nothing arrived, so there is nothing to read.
 */
final class TransportException extends RuntimeException
{
}
