<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The PasskeyFormField enum. The fields a form carrying a passkey ceremony sends back: which
 * ceremony, and what the browser's authenticator answered, each as base64url.
 *
 * The server writes the first; the client fills in the rest once the authenticator has answered, so
 * the names are a fact both languages know — mirrored in `assets/ts/model/PasskeyFormField.ts`, and
 * compared case for case by the site's parity test.
 */
enum PasskeyFormField: string implements Parameter
{
    /** Which {@link \Phpanta\Model\Passkey\EntranceCeremony} an entrance form asks for. */
    case Ceremony = 'ceremony';

    /** The credential's id. */
    case Credential = 'credential';

    /** The `clientDataJSON` bytes. */
    case ClientData = 'client-data';

    /** The authenticator data. */
    case AuthenticatorData = 'authenticator-data';

    /** An assertion's signature, DER. */
    case Signature = 'signature';

    /** A registration's public key, SPKI DER. */
    case Key = 'key';
}
