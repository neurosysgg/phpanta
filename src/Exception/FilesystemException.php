<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use RuntimeException;

/**
 * The FilesystemException class. Thrown when the filesystem refuses something the framework cannot
 * go on without — a directory it creates to work in.
 *
 * **It exists so the refusal is where it happened.** The quiet alternative is a directory handed
 * back that is not there, and the first write into it failing later, somewhere that names neither
 * the directory nor why.
 *
 * **Extends `RuntimeException`**, for {@link ThrottleException}'s reason: whether a directory can be
 * made is a fact about the host at the moment of asking — a full disk, a permission — and not about
 * the line that asked.
 */
class FilesystemException extends RuntimeException implements SiteException
{
}
