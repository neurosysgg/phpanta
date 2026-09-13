<?php

declare(strict_types=1);

namespace Phpanta\Tool\Http;

use RuntimeException;

/**
 * The TransportException class. Thrown when a request never became a response.
 *
 * Distinct from a response that says no. A 401, a 422 and a 503 all arrived, and what to do about
 * each is the caller's decision — see {@link \NeuroSYS\Tool\SoundCloud\SoundCloudException}, which
 * is where those turn into something with a message. This is the other thing: DNS, TLS, a
 * connection that died halfway through a 45 MB body. Nothing arrived, so there is nothing to read.
 */
final class TransportException extends RuntimeException
{
}
