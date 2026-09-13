<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Response;
use Phpanta\Model\Health\Area;
use Phpanta\Model\Health\HealthResult;
use Phpanta\Model\Health\Requirement;
use Phpanta\Support\Collection;

/**
 * The HealthCheck class. Whether this host meets what this installation needs of it.
 *
 * **Verdicts only.** Every line is a requirement with a floor — see
 * {@link \Phpanta\Support\RequirementInitialization} — and anything merely worth knowing about the
 * host is `capability`'s to say, with no verdict attached. That split is the design: a report that
 * mixed the two could not answer "is anything wrong" without a reader deciding, line by line, which
 * lines were claims.
 *
 * **A required requirement unmet is a `503`, and the body is the whole report either way**; see
 * {@link HealthResult}. The response is *returned* rather than thrown, and that is not style:
 * {@link \Phpanta\Controller\ApiController} turns an {@link \Phpanta\Exception\ApiException} into
 * a `422`, which would report an unhealthy host as a malformed request.
 *
 * **It takes the requirements rather than reading them**, which is the seam every test of it
 * needs: the declared set checks this machine's webroot, and a CLI run has none.
 *
 * **It reads no query parameter and never will.** The signature does not cover the query string,
 * so a parameter read here would be the one input reaching a verified handler unsigned. That is why
 * one area is asked for with an address of its own — `health v1 settings` — rather than a
 * `?area=`; see `docs/security.md`.
 *
 * A read: it writes nothing and consumes no serial.
 */
final readonly class HealthCheck implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<Requirement> $requirements
     * @param Area|null $area The one area to check, or null for every area.
     */
    public function __construct(private Collection $requirements, private ?Area $area = null) {}

    /**
     * @return bool
     */
    public function isWrite(): bool
    {
        return false;
    }

    /**
     * @return Response
     */
    public function handle(): Response
    {
        $result = HealthResult::of($this->requirements, $this->area);

        return new PlainTextResponse($result->status(), $result->render() . "\n");
    }
}
