<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use Phpanta\Exception\UpdateException;
use Phpanta\Http\HttpMethod;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Update\UpdateManifest;
use Phpanta\Service\Api\UpdatePatch;
use Phpanta\Service\Api\UpdateVersion;

/**
 * The UpdateAction enum. What the `update` service can be asked to do.
 *
 * Two cases, and they are the two halves of a deploy: send the tree, and ask what is running — so
 * that what is deployed is a question the endpoint answers rather than something read off the
 * asset URLs in the home page's markup.
 */
enum UpdateAction: string implements ApiAction
{
    /**
     * Write the payload's tree, and delete what it omits unless told not to.
     *
     * The one action on this site that changes anything. Its own manifest fields — `apply` and
     * `mirror` — are {@link UpdateManifest}'s, parsed out of the same signed bytes the envelope
     * came from, so a dry run cannot be turned into a real one by anything short of the private
     * key.
     */
    case Patch = 'patch';

    /**
     * Report what is deployed: the last serial accepted, the asset build stamp, the PHP version.
     *
     * A read, so it carries no body and consumes no serial. It exists because "did my push
     * actually land" had no cheap answer — and because the build stamp is the one fact that
     * distinguishes a deploy that wrote from one that found every file already current.
     */
    case Version = 'version';

    /**
     * @return HttpMethod
     */
    public function method(): HttpMethod
    {
        return match ($this) {
            self::Patch   => HttpMethod::Post,
            self::Version => HttpMethod::Get,
        };
    }

    /**
     * @param VerifiedRequest $verified
     * @return ApiHandler
     *
     * @throws UpdateException if a patch's manifest is missing `apply` or `mirror`, or either
     *                         is not a bool.
     */
    public function handler(VerifiedRequest $verified): ApiHandler
    {
        return match ($this) {
            // The manifest is parsed here rather than inside the handler so that a malformed one is
            // reported before anything has been attempted, which is the only point at which
            // "nothing was written" is still true without having to be asserted.
            self::Patch   => new UpdatePatch(UpdateManifest::parse($verified->manifest), $verified->body),
            self::Version => new UpdateVersion(),
        };
    }
}
