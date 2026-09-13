<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The CookieName enum. The cookies the framework reads.
 *
 * A cookie's name is the same kind of fact a header's is: both ends have to spell it identically,
 * and a misspelling is not an error but a cookie nobody reads — a choice a visitor made that is
 * silently ignored on every request after. So it is a case, for the reason {@link RequestHeader}
 * is an enum.
 */
enum CookieName: string
{
    /**
     * The language a visitor chose, as a {@link \Phpanta\Text\Language} value — `de` or `en`.
     *
     * Read by {@link Request::language()}, where it outranks `Accept-Language`.
     */
    case Language = 'lang';
}
