<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Update\ApplyManifest;
use Phpanta\Service\FilesystemProbe;

/**
 * The UpdateProbe class. The third action that writes: what the deployment's filesystem lets a push
 * do, measured by doing it in a scratch directory.
 *
 * **A write, although all it leaves is nothing.** It creates, renames and removes, so it takes the
 * lock every write takes and spends a serial — which is what keeps it from ever running beside a
 * push, and what keeps a captured probe from being replayed into a stream of directories on the
 * server. A dry run names the directory it would use and writes nothing, and spends nothing, for
 * {@link UpdatePatch::isWrite()}'s reason.
 *
 * What it runs is {@link FilesystemProbe}, which has the argument for each measurement.
 */
final readonly class UpdateProbe implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param ApplyManifest $manifest What this probe asks for, read out of the signed bytes.
     * @param FilesystemProbe|null $probe A test seam, the way {@link UpdateRollback}'s applier is.
     *                                    Production passes nothing, which resolves the live
     *                                    deployment.
     */
    public function __construct(
        private ApplyManifest    $manifest,
        private ?FilesystemProbe $probe = null,
    ) {}

    /**
     * A probe writes, and a dry run of one does not — `apply` decides.
     *
     * @return bool
     */
    public function isWrite(): bool
    {
        return $this->manifest->apply;
    }

    /**
     * @return ApiResult
     */
    public function handle(): ApiResult
    {
        $report = ($this->probe ?? new FilesystemProbe())->run($this->manifest->apply);

        return ApiResult::of(
            $report->isClean() ? HttpStatusCode::Ok : HttpStatusCode::InternalServerError,
            $report->section(),
        );
    }
}
