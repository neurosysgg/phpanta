<?php

declare(strict_types=1);

namespace Phpanta\Model\Passkey;

/**
 * The ChallengePurpose enum. What a challenge was minted for — so an answer to one cannot be spent on
 * another.
 *
 * The entrance's one challenge serves both of its ceremonies, an unlock and a registration, because a
 * session holds one challenge at a time and the entrance offers both at once; which ceremony answered
 * it is the client data's own `type`, which {@link \Phpanta\Service\Passkey\PasskeyVerifier} checks.
 */
enum ChallengePurpose: string
{
    /** At the entrance: opening the admin in this browser, or registering this device. */
    case Entrance = 'entrance';

    /** One write, at one address, by one method. */
    case Write = 'write';
}
