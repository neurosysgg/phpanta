<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Data\Column;

/**
 * The columns of {@link TableFixture::Authors} — the table a note's foreign key points at, and the
 * second `id` in a join.
 */
enum AuthorColumnFixture: string implements Column
{
    case Id   = 'id';
    case Name = 'name';
}
