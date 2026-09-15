<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use Phpanta\Http\HttpMethod;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Service\Api\CapabilityDeployment;
use Phpanta\Service\Api\CapabilityErrors;
use Phpanta\Service\Api\CapabilityExtensions;
use Phpanta\Service\Api\CapabilityRuntime;
use Phpanta\Service\Api\CapabilitySettings;
use Phpanta\Support\Collection;
use Phpanta\Text\AdminText;
use Phpanta\Text\Translatable;

/**
 * The CapabilityAction enum. What the `capability` service can be asked to list.
 *
 * **What the host says about itself, and nothing about whether that is enough.** Each action is an
 * inventory with no verdict in it — every extension, every directive — and a line only becomes a
 * claim once {@link \Phpanta\Support\RequirementInitialization} declares a floor for it, at which
 * point it is `health`'s to check. The one deliberate overlap is extensions: this lists what is
 * *registered*, and `health` proves what *works*.
 *
 * Every case is a read.
 */
enum CapabilityAction: string implements ApiAction
{
    /** The interpreter and the machine under it, and its clock. */
    case Runtime = 'runtime';

    /** Every extension the engine has loaded, and its version. */
    case Extensions = 'extensions';

    /** Every php.ini directive the engine knows, and its value. */
    case Settings = 'settings';

    /** Where this installation serves from, and which of the files it reads are there. */
    case Deployment = 'deployment';

    /** Where a diagnostic goes, the last one that got there, and the log's tail. */
    case Errors = 'errors';

    /**
     * @return HttpMethod
     */
    public function method(): HttpMethod
    {
        return HttpMethod::Get;
    }

    /**
     * @return Translatable
     */
    public function describe(): Translatable
    {
        return match ($this) {
            self::Runtime    => AdminText::CapabilityRuntime,
            self::Extensions => AdminText::CapabilityExtensions,
            self::Settings   => AdminText::CapabilitySettings,
            self::Deployment => AdminText::CapabilityDeployment,
            self::Errors     => AdminText::CapabilityErrors,
        };
    }

    /**
     * None; see {@link HealthAction::fields()}.
     *
     * @return Collection<ActionField>
     */
    public function fields(): Collection
    {
        return new Collection(ActionField::class);
    }

    /**
     * Every inventory, since each only reads.
     *
     * @return bool
     */
    public function fromBrowser(): bool
    {
        return true;
    }

    /**
     * None: an inventory is of the host, not of a path on it.
     *
     * @return bool
     */
    public function takesPath(): bool
    {
        return false;
    }

    /**
     * No handler takes anything from the manifest; see {@link HealthAction::handler()}.
     *
     * @param VerifiedRequest $verified
     * @param string|null     $path     Never one; see {@link self::takesPath()}.
     * @return ApiHandler
     */
    public function handler(VerifiedRequest $verified, ?string $path = null): ApiHandler
    {
        return match ($this) {
            self::Runtime    => new CapabilityRuntime(),
            self::Extensions => new CapabilityExtensions(),
            self::Settings   => new CapabilitySettings(),
            self::Deployment => new CapabilityDeployment(),
            self::Errors     => new CapabilityErrors(),
        };
    }
}
