<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Passkey\Passkey;
use Phpanta\Service\Passkey\PasskeyRegistry;

/**
 * The AccessPasskeys class. `access v1 passkeys`: the enrolled devices, each by its name, with its
 * fingerprint — the one the entrance showed when it registered — its credential id, and when it came in.
 *
 * A read. What a revocation names a device by is its credential id, which is why the id is here.
 */
final readonly class AccessPasskeys implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param PasskeyRegistry $registry Where devices are kept; the app's by default.
     */
    public function __construct(private PasskeyRegistry $registry = new PasskeyRegistry()) {}

    /**
     * @return bool
     */
    public function isWrite(): bool
    {
        return false;
    }

    /**
     * @return ApiResult
     */
    public function handle(): ApiResult
    {
        $passkeys = $this->registry->all();

        return ApiResult::of(HttpStatusCode::Ok, $passkeys->isEmpty()
            ? HealthSection::lines(null, 'no device is enrolled')
            : HealthSection::facts('passkeys', $passkeys->map(static fn(Passkey $passkey): HealthFact => new HealthFact(
                $passkey->name,
                sprintf('%s  %s  %s', $passkey->fingerprint(), $passkey->id, $passkey->added),
            ))));
    }
}
