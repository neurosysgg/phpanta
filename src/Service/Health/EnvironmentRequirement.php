<?php

declare(strict_types=1);

namespace Phpanta\Service\Health;

use Phpanta\App;
use Phpanta\Environment;
use Phpanta\Http\ServerVariable;
use Phpanta\Model\Health\Area;
use Phpanta\Model\Health\Finding;
use Phpanta\Model\Health\Level;
use Phpanta\Model\Health\Requirement;

/**
 * The EnvironmentRequirement class. A deployment reports itself as production.
 *
 * Optional, and so a warning rather than a failure: a developer's own deployment is development on
 * purpose, and its health report should say so without calling the host broken. On a host that
 * serves anybody else, a warning here is the first of the two conditions a trace is shown on already
 * met — see {@link Environment}.
 */
final readonly class EnvironmentRequirement implements Requirement
{
    /**
     * @return string
     */
    public function name(): string
    {
        return ServerVariable::Environment->value;
    }

    /**
     * @return Area
     */
    public function area(): Area
    {
        return Area::Deployment;
    }

    /**
     * @return Level
     */
    public function level(): Level
    {
        return Level::Optional;
    }

    /**
     * @return string
     */
    public function expected(): string
    {
        return Environment::Production->value;
    }

    /**
     * @return Finding
     */
    public function check(): Finding
    {
        $environment = App::current()->environment();

        return new Finding($environment->value, $environment === Environment::Production);
    }
}
