<?php

declare(strict_types=1);

namespace Phpanta\Exception;

use RuntimeException;

/**
 * The ApiException class. Thrown when a signed API request cannot be read or cannot be trusted.
 *
 * **Extends `RuntimeException` rather than `LogicException`, which is the opposite classification
 * from {@link RouteException} and deliberately so.** A route filled in wrongly is a line in this
 * repository; a malformed credential arrived over the network, from a caller this code does not
 * control. It is data, so it is a condition rather than a bug, and something does catch it —
 * {@link \Phpanta\Service\ApiGate}, which turns every one of them into the same silence.
 *
 * **{@link UpdateException} is a subclass, and that is what makes one `catch` enough.** The
 * arrangement is {@link MarkupException}'s — a base naming what its family has in common, so a
 * `catch` at the boundary covers all of it without listing the members. The difference is that this
 * base is **concrete**: {@link \Phpanta\Model\Api\ApiCredential} and
 * {@link \Phpanta\Model\Api\ApiEnvelope} genuinely throw it, where nothing throws a bare
 * `MarkupException`. What is left under `Update` is the tar, the applier and the manifest — the
 * parts that are about one service rather than about every signed request.
 *
 * That two-sided handling is why the message matters and why it is never sent to an unverified
 * caller. Everything thrown out of the gate is swallowed and answered with the site's ordinary "no
 * such path"; everything thrown past it is reported in full, because by then the caller has proved
 * it holds the private key.
 */
class ApiException extends RuntimeException implements SiteException
{
}
