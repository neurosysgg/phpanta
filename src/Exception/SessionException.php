<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use RuntimeException;

/**
 * The SessionException class. Thrown when a session cannot be kept as asked: no key to seal it
 * with, a key of the wrong shape, a session too large for the cookie it lives in, or a key the site
 * chose that collides with the framework's own.
 *
 * **A session that cannot be read is not this.** A cookie that was tampered with, sealed under
 * another key, or kept past its expiry is simply no session — an anonymous visitor, which is a
 * condition a request may put the site in and nothing to throw about. This is the site's own
 * deployment or code not holding up its end, and it stops.
 */
class SessionException extends RuntimeException implements SiteException
{
}
