<?php

declare(strict_types=1);

namespace Phpanta\Model\Passkey;

/**
 * The CeremonyType enum. Which WebAuthn ceremony a browser says it ran, as `clientDataJSON` names it.
 *
 * Checked on every answer, because the two sign different things for different reasons: a
 * registration that could be replayed as an unlock — or the other way round — would let one
 * ceremony's answer stand in for the other's.
 */
enum CeremonyType: string
{
    /** `navigator.credentials.create()`: a new key, registered. */
    case Create = 'webauthn.create';

    /** `navigator.credentials.get()`: an existing key, asserting. */
    case Get = 'webauthn.get';
}
