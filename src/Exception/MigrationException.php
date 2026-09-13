<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use LogicException;

/**
 * The MigrationException class. Thrown when a list of migrations cannot be squared with the
 * history a database records: an applied migration renamed, removed or moved, or a list that names
 * one id twice or none at all.
 *
 * **Extends `LogicException`**: the list is code, and a list that disagrees with what has already
 * been done to the live database is code written wrong. The one fix is in the list — put the
 * applied migration back as it was, and write a new one for the change — never in the database,
 * whose history is the only record of what actually ran.
 */
class MigrationException extends LogicException implements SiteException
{
}
