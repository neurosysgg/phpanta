<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

/**
 * The Area enum. Which part of a host a {@link Requirement} is about.
 *
 * **Each case is also an address**: `health v1 <area>` checks the requirements in one area, and
 * `health v1 report` checks every area in the order declared here. So an area is not a label for
 * grouping lines — it is the unit a caller can ask for — and {@link \Phpanta\Http\Api\HealthAction}
 * has a case for each, which `HealthTest` holds it to.
 *
 * Four, and deliberately not open-ended. A requirement a user declares for themselves picks one of
 * these rather than inventing a fifth, because an area is an address and the addresses under
 * `/admin` are enums: a string area would be a segment the router matched and nothing recognised.
 * `deployment` is the one to reach for when nothing else fits — it means "this installation", which
 * is where anything an application checks of its own surroundings belongs.
 *
 * Server-only, like every other enum reachable only through the admin: nothing the browser loads may
 * reach it, so there is no TypeScript mirror and none is wanted.
 */
enum Area: string
{
    /** The interpreter itself: its version, and anything else true of PHP before any extension. */
    case Runtime = 'runtime';

    /** What the interpreter was built or configured with. */
    case Extensions = 'extensions';

    /** php.ini: what the host lets one request do. */
    case Settings = 'settings';

    /** This installation: where it serves from, and what it expects to find there. */
    case Deployment = 'deployment';
}
