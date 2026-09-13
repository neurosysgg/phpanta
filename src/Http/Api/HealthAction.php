<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use Phpanta\App;
use Phpanta\Http\HttpMethod;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Health\Area;
use Phpanta\Service\Api\HealthCheck;

/**
 * The HealthAction enum. What the `health` service can be asked to check.
 *
 * **One case per {@link Area}, and one for all of them.** An area is the unit a caller asks for, and
 * it has to be an address rather than a parameter — the signature does not cover the query string,
 * so `?area=settings` would be the one input reaching a verified handler unsigned. `HealthTest`
 * holds this enum to {@link Area}, so an area added there without an address here fails.
 *
 * Every case is a read, and the only kind `health` will ever have: a service that checks is a
 * service that changes nothing, so it consumes no serial and its credential replays to another read.
 */
enum HealthAction: string implements ApiAction
{
    /** Every requirement, in every area, with a tally. */
    case Report = 'report';

    /** The interpreter's own version. */
    case Runtime = 'runtime';

    /** The extensions, each asked by being used where the declaration says how. */
    case Extensions = 'extensions';

    /** The php.ini floors. */
    case Settings = 'settings';

    /** This installation: its webroot, and the files it cannot run without. */
    case Deployment = 'deployment';

    /**
     * @return HttpMethod
     */
    public function method(): HttpMethod
    {
        return HttpMethod::Get;
    }

    /**
     * The one area this action checks, or null for every area.
     *
     * Matched rather than derived with `Area::from($this->value)`, which would work today and make
     * two enums' spellings one fact by coincidence — the arrangement this codebase spends its types
     * avoiding.
     *
     * @return Area|null
     */
    public function area(): ?Area
    {
        return match ($this) {
            self::Report     => null,
            self::Runtime    => Area::Runtime,
            self::Extensions => Area::Extensions,
            self::Settings   => Area::Settings,
            self::Deployment => Area::Deployment,
        };
    }

    /**
     * The handler takes nothing from the manifest, which is what a service with no parameters
     * looks like — and is why this signature declares no `@throws` where
     * {@link UpdateAction::handler()} declares one. There is no field to be missing.
     *
     * @param VerifiedRequest $verified
     * @return ApiHandler
     */
    public function handler(VerifiedRequest $verified): ApiHandler
    {
        return new HealthCheck(App::current()->requirements(), $this->area());
    }
}
