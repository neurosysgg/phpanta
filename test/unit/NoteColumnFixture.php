<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Data\Column;

/**
 * The columns of {@link TableFixture::Notes}, one of each kind a {@link \Phpanta\Data\Row} reads.
 */
enum NoteColumnFixture: string implements Column
{
    case Id     = 'id';
    case Author = 'author';
    case Title  = 'title';

    /** Text that may be `NULL`. */
    case Body = 'body';

    /** An integer that may be `NULL`. */
    case Rating = 'rating';

    /** Declared with no type at all, so what SQLite keeps is whatever it was handed. */
    case Weight = 'weight';

    /** A flag, as SQLite keeps one: 1 or 0. */
    case Pinned = 'pinned';

    /** Not a column of the table: the alias a test selects `typeof()` under. */
    case Kind = 'kind';
}
