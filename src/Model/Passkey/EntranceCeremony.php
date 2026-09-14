<?php

declare(strict_types=1);

namespace Phpanta\Model\Passkey;

/**
 * The EntranceCeremony enum. What a form posted to the admin's entrance asks for.
 *
 * Written by the server into each form it renders, as the {@link \Phpanta\Http\PasskeyFormField::Ceremony}
 * field, so the client has no vocabulary of its own to keep in step with this one.
 */
enum EntranceCeremony: string
{
    /** Open the admin in this browser, with an enrolled passkey. */
    case Unlock = 'unlock';

    /** Register this device, for an enrolment code the signing key then enrols. */
    case Register = 'register';

    /** Lock the admin in this browser again. */
    case Logout = 'logout';
}
