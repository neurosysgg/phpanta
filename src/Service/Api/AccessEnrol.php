<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Exception\ApiException;
use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\SessionSeal;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Passkey\AccessManifest;
use Phpanta\Model\Passkey\EnrolmentCode;
use Phpanta\Model\Passkey\Passkey;
use Phpanta\Service\Passkey\PasskeyRegistry;

/**
 * The AccessEnrol class. `access v1 enrol`: an enrolment code, turned into a device that may open the
 * admin.
 *
 * A write, since it changes who may come in; a dry run opens the code and says what it would enrol, and
 * writes nothing. Enrolling a device already enrolled renames it.
 */
final readonly class AccessEnrol implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param AccessManifest  $manifest What was asked for, read out of the signed bytes.
     * @param EnrolmentCode   $code     The code, opened.
     * @param PasskeyRegistry $registry Where devices are kept; the app's by default.
     */
    public function __construct(
        private AccessManifest  $manifest,
        private EnrolmentCode   $code,
        private PasskeyRegistry $registry = new PasskeyRegistry(),
    ) {}

    /**
     * The enrolment $manifest asks for, if its code opens under $seal at $now.
     *
     * @param AccessManifest  $manifest
     * @param SessionSeal     $seal
     * @param int             $now
     * @param PasskeyRegistry $registry
     * @return self
     * @throws ApiException if the code is not one this deployment made in the last ten minutes.
     */
    public static function open(
        AccessManifest $manifest,
        SessionSeal $seal,
        int $now,
        PasskeyRegistry $registry = new PasskeyRegistry(),
    ): self {
        $code = EnrolmentCode::open($seal, $manifest->code, $now)
            ?? throw new ApiException('that is not an enrolment code this deployment made in the last ten minutes');

        return new self($manifest, $code, $registry);
    }

    /**
     * `apply` decides, as for every write here.
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
        $passkey = new Passkey($this->code->credential, $this->manifest->name, $this->code->key, 0, date(DATE_ATOM));

        if (!$this->manifest->apply) {
            return ApiResult::of(
                HttpStatusCode::Ok,
                HealthSection::lines(null, 'a dry run: nothing was enrolled', 'would enrol ' . $passkey->label()),
            );
        }

        return $this->registry->keep($passkey)
            ? ApiResult::of(HttpStatusCode::Ok, HealthSection::lines(null, 'enrolled ' . $passkey->label()))
            : ApiResult::refusal(
                HttpStatusCode::InternalServerError,
                'the passkey store could not be written, so nothing was enrolled',
            );
    }
}
