<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use RuntimeException;

/**
 * The ThrottleException class. Thrown when a {@link \Phpanta\Support\Throttle} cannot remember an
 * attempt — its directory is missing or not writable, its lock cannot be taken, or a record cannot
 * be read or written.
 *
 * **It exists so a limiter fails closed, and loudly.** The quiet alternative is a throttle that
 * finds nowhere to write, counts nothing, and so allows everything: the page works, the log is
 * empty, and the limit that was deployed is not there. A throw is a 500 for the request in hand and
 * a line in the error log naming the directory, which is the whole of what anybody needs to fix it.
 *
 * **Extends `RuntimeException`**, because whether a directory is writable is a fact about the host
 * at the moment of asking — a deploy that left it out, a permission changed by hand — and not about
 * the line that named it. The same code counts on one server and throws on the next.
 */
class ThrottleException extends RuntimeException implements SiteException
{
}
