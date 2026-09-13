<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

/**
 * The ApiVersion enum. Which revision of a service's vocabulary a request is speaking.
 *
 * One case, like {@link \Phpanta\Http\RequestedWith} and
 * {@link \Phpanta\View\Html\LinkTarget} — it exists to make the segment a type, not to offer a
 * choice. A version that lived as the literal `'v1'` would be a segment the router matched and
 * nothing else recognised, which is the shape of drift every enum here exists to stop.
 *
 * **It is the second segment rather than the first, and that is a decision about what a version
 * versions.** `/api/v1/update/patch` would make one number govern every service at once, so adding
 * a field to one of them would either break the others' URLs or make the number a lie.
 * `/api/update/v1/patch` lets each service move on its own, which is the only arrangement that
 * survives a second service.
 *
 * **It is deliberately not the credential's version.** {@link \Phpanta\Http\AuthScheme::NS1}
 * carries that one, and the two move for different reasons: this changes when what a caller may ask
 * for changes, that changes when how the asking is signed changes. A single digit doing both jobs
 * would have to be bumped for either, which is how a version stops meaning anything.
 *
 * Server-only, and no TypeScript mirror is wanted: nothing the browser loads may reach `/api`, so a
 * case here would be a case in the bundle that no client code could ever have a use for.
 */
enum ApiVersion: string
{
    /** The first, and so far only, revision of every service's vocabulary. */
    case V1 = 'v1';
}
