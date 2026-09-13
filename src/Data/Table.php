<?php

declare(strict_types=1);

namespace Phpanta\Data;

use BackedEnum;

/**
 * The Table interface. A site's tables, as a string-backed enum — a case per table, the case's
 * value the table's name — for the reason {@link Column} gives for columns.
 *
 * A statement names a table by writing the case's value into its text, which is safe exactly
 * because the value is code: an enum case is written by the site's own hand and never by a
 * request. That is the one kind of thing a statement may have written into it; everything else is
 * a bound parameter. See {@link Sql}.
 */
interface Table extends BackedEnum
{
}
