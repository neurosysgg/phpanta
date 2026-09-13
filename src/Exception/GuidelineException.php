<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use InvalidArgumentException;

/**
 * The GuidelineException class. Thrown when an excuse for one of the guidelines has a hole in it.
 *
 * The three attributes that carry those excuses — {@link \Phpanta\Support\BareArray},
 * {@link \Phpanta\Support\BareString} and {@link \Phpanta\Support\BareCall} — each refuse an
 * empty reason, and the last two refuse an empty subject as well.
 *
 * **It is thrown where the mistake is, which is the whole point of the attributes checking
 * themselves at all.** {@link \NeuroSYS\Test\Unit\GuidelineTest} would notice the same fault, and
 * would report it against a list of forty other entries; the constructor reports it against the
 * line that is wrong. The same division of labour {@link \NeuroSYS\Model\Link\HiDriveLink} has with
 * its own tests, arrived at for the same reason.
 *
 * **Extends `InvalidArgumentException`** — an argument to a constructor is wrong, which is what
 * that means, and it is what these threw before they were named. `LogicException` would have been
 * true too but less specific, and `InvalidArgumentException` is one anyway.
 */
class GuidelineException extends InvalidArgumentException implements SiteException
{
}
