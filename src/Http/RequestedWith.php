<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The RequestedWith enum. The values {@link RequestHeader::RequestedWith} is read for.
 *
 * A value enum next to its header, the same way {@link Security\ContentTypeOptions} sits next to
 * the header it fills. The comparison is case-insensitive because the header is conventional
 * rather than standard and libraries disagree on its casing — so {@link self::matches()} owns that
 * rule instead of a `strtolower()` at the one call site that happens to remember it.
 */
enum RequestedWith: string
{
    case XmlHttpRequest = 'XMLHttpRequest';

    /**
     * True if $header names this value, whatever case it arrived in.
     *
     * @param string $header
     * @return bool
     */
    public function matches(string $header): bool
    {
        return strtolower($header) === strtolower($this->value);
    }
}
