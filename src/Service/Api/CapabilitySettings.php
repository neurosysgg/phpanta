<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Response;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Support\Collection;

/**
 * The CapabilitySettings class. Every php.ini directive the engine knows, and the value in force.
 *
 * **All of them, rather than the ones an app cares about.** A curated list is exactly what
 * cannot answer the question this is for — "does this host have a directive I have not thought
 * of" — and the ones an app does care about are floors, which are `health v1 settings`'s to
 * check. About three hundred lines, in the engine's own order, which is alphabetical.
 *
 * The value is the local one — what a request here runs under, after `.user.ini` and anything else
 * that overrides the master value — because that is the one that decides what a request can do. A
 * directive with no value reads as a dash.
 *
 * A read: it writes nothing and consumes no serial.
 */
final readonly class CapabilitySettings implements ApiHandler
{
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
        $facts = [];
        foreach (ini_get_all(null, false) as $directive => $value) {
            $facts[] = new HealthFact($directive, (string) $value);
        }

        return new PlainTextResponse(HttpStatusCode::Ok, HealthSection::document(
            HealthSection::facts('settings', new Collection(HealthFact::class)->with(...$facts)),
        ));
    }
}
