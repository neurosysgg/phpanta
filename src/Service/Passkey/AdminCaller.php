<?php

declare(strict_types=1);

namespace Phpanta\Service\Passkey;

use Phpanta\Http\Session;
use Phpanta\Model\Passkey\Passkey;

/**
 * The AdminCaller class. A browser the admin has let in: the enrolled passkey its session was unlocked
 * with, and that session.
 *
 * Asked for on every request rather than trusted from the cookie alone, so a passkey revoked a moment
 * ago opens nothing on the next request, whatever its session says.
 */
final readonly class AdminCaller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Passkey $passkey The passkey the session was unlocked with, as the store has it now.
     * @param Session $session The session, as the request carried it.
     */
    public function __construct(
        public Passkey $passkey,
        public Session $session,
    ) {}
}
