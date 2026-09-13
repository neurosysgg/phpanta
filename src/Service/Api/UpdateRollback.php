<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Exception\UpdateException;
use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Response;
use Phpanta\Model\Update\ApplyManifest;
use Phpanta\Service\UpdateApplier;

/**
 * The UpdateRollback class. The second action that writes: the release the last push replaced,
 * put back.
 *
 * {@link UpdatePatch}'s shape exactly, for its reasons: built only past the signature, so every
 * failure is reported in full; a refusal is thrown and becomes the one 422
 * {@link \Phpanta\Controller\ApiController} writes; a run that could not do everything is a 500
 * naming what it could not do. What it runs is {@link UpdateApplier::rollback()}, which has the
 * argument.
 */
final readonly class UpdateRollback implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param ApplyManifest $manifest What this rollback asks for, read out of the signed bytes.
     * @param UpdateApplier|null $applier A test seam, the way {@link UpdatePatch}'s is. Production
     *                                    passes nothing, which resolves the live deployment.
     */
    public function __construct(
        private ApplyManifest  $manifest,
        private ?UpdateApplier $applier = null,
    ) {}

    /**
     * A rollback writes, and a dry run of one does not — {@link UpdatePatch::isWrite()}'s rule, and
     * for its reason: `apply` decides, not the outcome.
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
     * @throws UpdateException if there is no complete record to roll back to, or the deployment has
     *                         moved on since it was taken. Nothing has been written when it does.
     */
    public function handle(): Response
    {
        $report = ($this->applier ?? new UpdateApplier())->rollback($this->manifest->apply);

        return new PlainTextResponse(
            $report->isComplete() ? HttpStatusCode::Ok : HttpStatusCode::InternalServerError,
            $report->render(),
        );
    }
}
