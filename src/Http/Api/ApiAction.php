<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use Phpanta\Exception\ApiException;
use Phpanta\Http\HttpMethod;
use Phpanta\Model\Api\VerifiedRequest;

/**
 * The ApiAction interface. One thing a service can be asked to do.
 *
 * Implemented by an enum per service — {@link UpdateAction} is the first — rather than by one flat
 * enum listing every action on the site. An action belongs to a service the way a case belongs to
 * its enum: `patch` means nothing on its own, and a shared list would have to be filtered by
 * service at every use, which is the check a type makes for free.
 *
 * The shape is {@link \NeuroSYS\Tool\Cli\Option}'s, arrived at the same way: several enums that are
 * interchangeable at one call site, with the interface saying so. {@link ApiService::action()} is
 * that call site, and it is the only place a service is mapped to its own set.
 */
interface ApiAction
{
    /**
     * The one method this action answers on.
     *
     * A single method rather than a set, because every action here is one verb by construction — a
     * read or a write, never both — and a set would let an action quietly accept a verb nobody
     * meant it to. It is checked **past** the gate, so a mismatch is a real `405` naming this
     * method, which only the key holder ever sees; an unsigned caller never gets that far.
     *
     * @return HttpMethod
     */
    public function method(): HttpMethod;

    /**
     * This action, ready to answer, built from what the gate verified.
     *
     * The handler parses whatever fields it owns out of `$verified->manifest` — the same signed
     * bytes {@link \Phpanta\Model\Api\ApiEnvelope} was read from, never a re-encoding of them —
     * so an action's own parameters are covered by the signature exactly as the envelope is.
     *
     * @param VerifiedRequest $verified
     * @return ApiHandler
     *
     * @throws ApiException if this action's own fields are missing from the manifest or mistyped.
     */
    public function handler(VerifiedRequest $verified): ApiHandler;
}
