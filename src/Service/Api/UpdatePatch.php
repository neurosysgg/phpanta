<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Exception\UpdateException;
use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Response;
use Phpanta\Model\Update\UpdateManifest;
use Phpanta\Service\UpdateApplier;

/**
 * The UpdatePatch class. The one action that writes: the signed tree, applied.
 *
 * It is the write path from the signature check down — the applier call, the 422 for an archive
 * that will not expand, the 500 for a push that could not write everything. It is not a
 * Controller: by the time one of these exists the request has been
 * verified, so there is no `Request` to consult and nothing left to refuse quietly. Every failure
 * from here on is reported in full, because the caller has proved it holds the private key and
 * there is nowhere else for that detail to go — a production host has `display_errors` off, and may
 * have an empty `error_log`.
 */
final readonly class UpdatePatch implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param UpdateManifest $manifest What this push asks for, already read out of the signed bytes.
     * @param string $archive The gzipped tar, already matched against the envelope's digest.
     * @param UpdateApplier|null $applier A test seam, the way {@link \Phpanta\Service\ApiGate}'s
     *                                    key and serial are. Production passes nothing, which is what
     *                                    makes {@link UpdateApplier} resolve the live deployment.
     */
    public function __construct(
        private UpdateManifest $manifest,
        private string         $archive,
        private ?UpdateApplier $applier = null,
    ) {}

    /**
     * A push writes, and a dry run is still a push that asked to.
     *
     * **`apply` decides, not the outcome.** A run that wrote nothing because every file was already
     * current has still spent its serial, and must — the bytes that produced it would otherwise be
     * accepted a second time. A *dry run* is the one that does not, deliberately: it writes
     * nothing, so leaving the serial alone lets the very same payload be sent for real, and a
     * captured dry run replays to nothing.
     *
     * @return bool
     */
    public function isWrite(): bool
    {
        return $this->manifest->apply;
    }

    /**
     * @return Response
     *
     * @throws UpdateException if the archive cannot be expanded, or holds a member the applier will
     *                         not write. **Nothing has been written when it does** — that is
     *                         {@link UpdateApplier::apply()}'s own contract, and it is what lets
     *                         this throw rather than catch: {@link \Phpanta\Controller\ApiController}
     *                         turns every refusal past the gate into one 422 in one place, and a
     *                         second phrasing of that sentence here would be the same fact written
     *                         twice.
     */
    public function handle(): Response
    {
        $report = ($this->applier ?? new UpdateApplier())->apply($this->archive, $this->manifest);

        return new PlainTextResponse(
            $report->isComplete() ? HttpStatusCode::Ok : HttpStatusCode::InternalServerError,
            $report->render(),
        );
    }
}
