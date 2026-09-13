<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use RuntimeException;

/**
 * The UploadException class. Thrown when the host could not keep a file a request sent: no
 * temporary directory, a write that failed, or an extension that stopped it.
 *
 * **Extends `RuntimeException`, not {@link InputException}**, because none of it is the sender's
 * doing — a visitor who sent a good file into a full disk has nothing to fix. So it is a 500 and a
 * line in the error log naming PHP's error code, which is what the host's owner needs to read.
 */
class UploadException extends RuntimeException implements SiteException
{
}
