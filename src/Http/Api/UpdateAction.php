<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use Phpanta\Exception\UpdateException;
use Phpanta\Http\HttpMethod;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Update\ApplyManifest;
use Phpanta\Model\Update\UpdateManifest;
use Phpanta\Service\Api\UpdatePatch;
use Phpanta\Service\Api\UpdateProbe;
use Phpanta\Service\Api\UpdateRollback;
use Phpanta\Service\Api\UpdateVersion;

/**
 * The UpdateAction enum. What the `update` service can be asked to do.
 *
 * Four cases: send the tree, ask what is running — so that what is deployed is a question the
 * endpoint answers rather than something read off the asset URLs in the home page's markup — take
 * the last push back, and measure what the deployment's filesystem lets a push do.
 */
enum UpdateAction: string implements ApiAction
{
    /**
     * Write the payload's tree, and delete what it omits unless told not to.
     *
     * The one action in the framework that changes anything. Its own manifest fields — `apply` and
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
     * Put back the release the last push replaced, from the record that push took.
     *
     * A write with no body: everything it restores is already on the server, so what the signature
     * covers is the request and its one field, `apply` — {@link ApplyManifest}'s. One step back
     * and no further; see {@link \Phpanta\Service\UpdateApplier::rollback()}.
     */
    case Rollback = 'rollback';

    /**
     * Measure what the deployment's filesystem lets a push do, in a scratch directory beside the
     * roots, and take it away again.
     *
     * A write with no body, like a rollback: it creates and removes, so it takes the lock and spends
     * a serial, and a dry run does neither. See {@link \Phpanta\Service\FilesystemProbe}.
     */
    case Probe = 'probe';

    /**
     * @return HttpMethod
     */
    public function method(): HttpMethod
    {
        return match ($this) {
            self::Patch    => HttpMethod::Post,
            self::Version  => HttpMethod::Get,
            self::Rollback => HttpMethod::Post,
            self::Probe    => HttpMethod::Post,
        };
    }

    /**
     * @param VerifiedRequest $verified
     * @return ApiHandler
     *
     * @throws UpdateException if a patch's manifest is missing `apply` or `mirror`, or a rollback's
     *                         or a probe's is missing `apply`, or any of them is not a bool.
     */
    public function handler(VerifiedRequest $verified): ApiHandler
    {
        return match ($this) {
            // The manifest is parsed here rather than inside the handler so that a malformed one is
            // reported before anything has been attempted, which is the only point at which
            // "nothing was written" is still true without having to be asserted. The serial rides
            // along so the record the push takes says which push took it.
            self::Patch    => new UpdatePatch(
                UpdateManifest::parse($verified->manifest),
                $verified->body,
                serial: $verified->envelope->serial,
            ),
            self::Version  => new UpdateVersion(),
            self::Rollback => new UpdateRollback(ApplyManifest::parse($verified->manifest, $this->value)),
            self::Probe    => new UpdateProbe(ApplyManifest::parse($verified->manifest, $this->value)),
        };
    }
}
