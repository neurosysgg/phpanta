<?php

declare(strict_types=1);

namespace Phpanta\Exception;

/**
 * The UpdateException class. Thrown when an update payload cannot be read or cannot be applied.
 *
 * **A subclass of {@link ApiException} rather than a sibling of it**, which is the arrangement
 * {@link MarkupException} has with its three: one `catch` at the gate covers every way a signed
 * request can fail, and neither this class nor a later one has to be listed there. It said
 * `extends RuntimeException` before there was an API around it, and `ApiException` is a
 * `RuntimeException`, so nothing a caller could do changed — only that the throw now says which
 * layer raised it.
 *
 * The split is by scope rather than by severity. Its parent is about **any** signed request: a
 * credential that will not decode, an envelope that does not describe the request carrying it.
 * This is about the one service that writes — the tar reader, {@link \Phpanta\Service\UpdateApplier}
 * and {@link \Phpanta\Model\Update\UpdateManifest}, none of which a read has any use for.
 *
 * Everything thrown before {@link \Phpanta\Support\PublicKey} has passed is swallowed and answered
 * with the site's ordinary "no such path"; everything thrown after it is reported in full, because
 * by then the caller has proved it holds the private key.
 */
class UpdateException extends ApiException
{
}
