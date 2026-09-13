<?php

declare(strict_types=1);

namespace Phpanta\Service\Health;

use Phpanta\App;
use Phpanta\Exception\UpdateException;
use Phpanta\Http\ServerVariable;
use Phpanta\Model\Health\Area;
use Phpanta\Model\Health\Finding;
use Phpanta\Model\Health\Level;
use Phpanta\Model\Health\Requirement;

/**
 * The WebrootRequirement class. `DOCUMENT_ROOT` resolves to a webroot inside this deployment.
 *
 * **A requirement of the installation's rather than of the core's**, which is why it lives here
 * and not under `Model\Health`: it asks {@link App::webroot()}, and nothing in the core may know
 * the app. It is also the first use of the extension point by the code that defines it —
 * see {@link Requirement}.
 *
 * Required, because a push mirrors into this directory: a deployment whose webroot will not resolve
 * cannot be updated over `/api` at all, and has to be fixed with a full deploy.
 *
 * **The refusal is caught and becomes the finding**, which is the contract {@link Requirement}
 * states. `App::webroot()` refuses with an {@link UpdateException}, and left alone that would
 * reach {@link \Phpanta\Controller\ApiController}'s catch and turn the whole report into a 422. A
 * health check that will not report because something is unhealthy is not a health check; the
 * refusal's own sentence is the most useful thing on this line.
 */
final readonly class WebrootRequirement implements Requirement
{
    /**
     * Named for the variable rather than for the directory, because the variable is what is
     * checked: the webroot is what it resolves to, and the finding says which.
     *
     * @return string
     */
    public function name(): string
    {
        return ServerVariable::DocumentRoot->value;
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
        return Level::Required;
    }

    /**
     * @return string
     */
    public function expected(): string
    {
        return 'a directory inside this deployment';
    }

    /**
     * @return Finding
     */
    public function check(): Finding
    {
        try {
            return new Finding(App::current()->webroot()->path, true);
        } catch (UpdateException $refusal) {
            return new Finding($refusal->getMessage(), false);
        }
    }
}
