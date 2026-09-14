<?php

declare(strict_types=1);

namespace Phpanta\Model\Passkey;

/**
 * The PasskeyField enum. The members a stored passkey — and an enrolment code on its way to becoming
 * one — is written with, spelled once.
 */
enum PasskeyField: string
{
    /** The credential id, base64url, as the browser names the credential. */
    case Id = 'id';

    /** What the person who enrolled it called it: "phone", "laptop". */
    case Name = 'name';

    /** The public key, as SPKI DER, base64url. */
    case Key = 'key';

    /** The last signature count the authenticator reported. */
    case Count = 'count';

    /** When it was enrolled — or, for a code, when the browser registered it. */
    case Added = 'added';
}
