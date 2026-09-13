<?php

declare(strict_types=1);

namespace Phpanta\Data;

use BackedEnum;

/**
 * The Column interface. One table's columns, as a string-backed enum a site declares — one enum per
 * table, a case per column, the case's value the column's name.
 *
 * **A column is a name, and a name is typed.** A reader written `$row->string('titel')` is a
 * misspelling that fails only when that row is read, on whichever page reads it; written
 * `$row->string(PostColumn::Title)` it is a parse error, and every place that reads the column is
 * one "find usages" away. {@link Row}'s readers take nothing else.
 *
 * It declares no member of its own: what a column is, a backed case already says. The interface
 * exists so a reader can say "a column of some table" without naming the site's enum.
 */
interface Column extends BackedEnum
{
}
