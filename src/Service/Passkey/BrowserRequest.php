<?php

declare(strict_types=1);

namespace Phpanta\Service\Passkey;

use Phpanta\Http\Session;
use Phpanta\Model\Api\VerifiedRequest;

/**
 * The BrowserRequest class. What a browser's write became: the request an action's handler reads, or
 * none where the write was not answered by the passkey it needed — and, either way, the session to
 * send back, whose challenge is spent.
 */
final readonly class BrowserRequest
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param VerifiedRequest|null $verified The write, as a handler reads it; null where it was refused.
     * @param Session              $session  The session to attach to the answer.
     */
    public function __construct(
        public ?VerifiedRequest $verified,
        public Session          $session,
    ) {}
}
