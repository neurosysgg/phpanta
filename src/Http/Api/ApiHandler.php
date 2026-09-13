<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use Phpanta\Http\Response;

/**
 * The ApiHandler interface. One resolved API action, holding everything it needs to answer.
 *
 * It is {@link \Phpanta\Controller\Controller} one layer in, and the difference is what has
 * already happened by the time one exists: a Controller is built from a URL and asks its own
 * questions about the request, where a handler is built only **after**
 * {@link \Phpanta\Service\ApiGate} has proved the caller holds the private key. So it takes no
 * `Request` — everything a handler is allowed to act on is signed, and a handler reaching back for
 * the unsigned request would be reaching around the gate.
 *
 * That is also why {@link self::handle()} takes nothing at all. An implementation is constructed
 * from its {@link \Phpanta\Model\Api\VerifiedRequest} by
 * {@link ApiAction::handler()}, so the parse of whatever fields the action owns happens where it
 * can still be reported — before anything has been done — rather than halfway through answering.
 */
interface ApiHandler
{
    /**
     * Whether answering this changes the deployment.
     *
     * **This is what consumes the replay serial**, so it is a question about intent rather than
     * about outcome: a push that fails partway still burns its serial, because the bytes that
     * caused it must never be accepted a second time. A read answers false, which is what lets two
     * API calls be made in the same second — the serial is `time()` and the freshness rule is
     * *strictly* greater, so a read that consumed one would refuse whatever came next that second.
     *
     * The cost is that a captured read credential is replayable inside the 300-second skew window.
     * What that yields is the answer to a read, to someone who has already broken TLS, and it is
     * the deliberate side of the trade rather than an oversight.
     *
     * @return bool
     */
    public function isWrite(): bool;

    /**
     * Answers the request.
     *
     * Past the gate the site's posture inverts completely: every failure here is reported in full,
     * with the sentence that says what went wrong, because the caller has proved possession of the
     * private key and there is nowhere else for that detail to go — the live host has
     * `display_errors` off and an empty `error_log`.
     *
     * No `#[\NoDiscard]`, and its absence is the codebase's rule rather than an oversight: PHP
     * resolves the attribute at the *implementation*, so one here would warn about nothing, and
     * neither {@link \Phpanta\Controller\Controller::handle()} nor
     * {@link \Phpanta\Http\Response::send()} carries one either. Every carrier under `src/` is a
     * concrete builder or a gate's decision — see {@link \Phpanta\Service\ApiGate::accepts()},
     * which is one.
     *
     * @return Response
     */
    public function handle(): Response;
}
