<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

use Phpanta\Http\HttpStatusCode;

/**
 * The DropRefusal enum. Why a link revealed nothing.
 *
 * **Two answers, and the first is five kinds of nothing.** A token that is no token, one that names no
 * drop, a drop that has expired, one read once already, one whose file is not whole: each is
 * {@link self::Absent}, so a caller cannot tell a drop that was never there from one that has gone. A
 * password is asked for only of somebody who holds the link, who knows the drop is there already.
 */
enum DropRefusal: string
{
    /** Nothing opens at this link — it expired, was read once, or never was. */
    case Absent = 'absent';

    /** It is there, and needs its password — none was given, or not that one. */
    case Locked = 'locked';

    /**
     * The status the refusal is answered with.
     *
     * @return HttpStatusCode
     */
    public function status(): HttpStatusCode
    {
        return match ($this) {
            self::Absent => HttpStatusCode::NotFound,
            self::Locked => HttpStatusCode::Forbidden,
        };
    }
}
