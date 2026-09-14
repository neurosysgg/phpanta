<?php

declare(strict_types=1);

namespace Phpanta\Model\Passkey;

/**
 * The ClientDataKey enum. The members of a browser's `clientDataJSON` the admin reads — W3C WebAuthn
 * §5.8.1's spelling, written once.
 */
enum ClientDataKey: string
{
    /** Which ceremony it was — a {@link CeremonyType}. */
    case Type = 'type';

    /** The challenge the page handed the browser, base64url, as the browser saw it. */
    case Challenge = 'challenge';

    /** The origin of the page that ran the ceremony, as the browser saw it. */
    case Origin = 'origin';

    /** Whether the ceremony ran in a frame of another origin; absent means no. */
    case CrossOrigin = 'crossOrigin';
}
