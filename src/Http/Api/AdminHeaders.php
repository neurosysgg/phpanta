<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use Phpanta\Http\CacheControl;
use Phpanta\Http\Header;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RobotsPolicy;
use Phpanta\Support\Collection;

/**
 * The AdminHeaders class. What every answer under `/admin` carries, whoever gives it: kept by no
 * cache, since each is one caller's, and not to be indexed. Every answer at `/drop` carries the same
 * two, for the same reasons — see {@link \Phpanta\Controller\DropController}.
 */
final class AdminHeaders
{
    /**
     * The two, then $headers.
     *
     * @param Header ...$headers What one answer adds.
     * @return Collection<Header>
     */
    public static function with(Header ...$headers): Collection
    {
        return new Collection(Header::class)->with(
            new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
            new Header(ResponseHeader::Robots, RobotsPolicy::hide()),
            ...$headers,
        );
    }
}
