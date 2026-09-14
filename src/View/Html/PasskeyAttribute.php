<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

/**
 * The PasskeyAttribute enum. What marks a form as one a passkey answers before it is sent, for the
 * client module that runs the ceremony.
 *
 * Mirrored in `assets/ts/model/PasskeyAttribute.ts`, and compared case for case by the site's parity
 * test.
 */
enum PasskeyAttribute: string implements AttributeName
{
    /** Which ceremony the form runs — a {@link \Phpanta\Model\Passkey\CeremonyType} value. */
    case Ceremony = 'data-passkey';

    /** The challenge the server minted for it, base64url. */
    case Challenge = 'data-challenge';

    /**
     * What the form says when its authenticator did not answer — written by the server in the page's
     * language, hidden, and shown by the client module, which has no words of its own.
     */
    case Status = 'data-passkey-status';

    /**
     * @return string
     */
    public function attribute(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function isUrl(): bool
    {
        return false;
    }
}
