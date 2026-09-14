<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Exception\ApiException;
use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Passkey\AccessManifest;
use Phpanta\Service\Passkey\PasskeyRegistry;

/**
 * The AccessRevoke class. `access v1 revoke`: one device's passkey, taken away.
 *
 * A write; a dry run names the device it would revoke and writes nothing. It takes effect on the
 * device's next request: the admin asks the store for a browser's passkey every time, so a session
 * the device unlocked earlier opens nothing once its passkey is gone.
 */
final readonly class AccessRevoke implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param AccessManifest  $manifest What was asked for, read out of the signed bytes.
     * @param PasskeyRegistry $registry Where devices are kept; the app's by default.
     */
    public function __construct(
        private AccessManifest  $manifest,
        private PasskeyRegistry $registry = new PasskeyRegistry(),
    ) {}

    /**
     * @return bool
     */
    public function isWrite(): bool
    {
        return $this->manifest->apply;
    }

    /**
     * @return ApiResult
     * @throws ApiException if no enrolled device has the credential id asked for.
     */
    public function handle(): ApiResult
    {
        $passkey = $this->registry->find($this->manifest->passkey)
            ?? throw new ApiException('no enrolled device has that credential id');

        if (!$this->manifest->apply) {
            return ApiResult::of(
                HttpStatusCode::Ok,
                HealthSection::lines(null, 'a dry run: nothing was revoked', 'would revoke ' . $passkey->label()),
            );
        }

        return $this->registry->forget($passkey->id)
            ? ApiResult::of(HttpStatusCode::Ok, HealthSection::lines(null, 'revoked ' . $passkey->label()))
            : ApiResult::refusal(
                HttpStatusCode::InternalServerError,
                'the passkey store could not be written, so nothing was revoked',
            );
    }
}
