<?php

declare(strict_types=1);

namespace Phpanta;

/**
 * The CredentialFile enum. The four files in `data/` the framework itself reads: the two gates'
 * credentials, the API's key, and the key sessions are sealed with.
 *
 * **Every one of them changes what the site does rather than what it shows**, which is why they
 * are the framework's rather than a site's: the site gate, the admin gate and the signed API are
 * framework code, and each is switched by whether its file is there. Two of the three are switched
 * in opposite directions, and that is the thing to read twice — see {@link self::SiteAuth} and
 * {@link self::UpdateKey}.
 *
 * All four hold live credentials, so a full deploy excludes all four; each that a deployment needs is
 * uploaded, or minted, by hand.
 */
enum CredentialFile: string implements DataFileName
{
    /** bcrypt credentials for the admin gate. The repo copy is a placeholder; the deploy skips it. */
    case Admin = 'admin.php';

    /**
     * The pre-launch site gate's credentials, whose **absence is the off switch**.
     *
     * The one file here whose presence changes what the site does rather than what it shows, which
     * makes it the one where a misspelling is not merely quiet but inverted: a typo reads as "no
     * such file", and no such file means the gate stands down.
     */
    case SiteAuth = 'site_auth.php';

    /**
     * The ECDSA public key the admin verifies every signed call against — the public half, and only
     * ever that.
     *
     * **Its absence is the off switch, which is {@link self::SiteAuth}'s arrangement with the
     * polarity reversed.** No key file, no signed call verifies: {@link Service\ApiGate} refuses
     * every request, and {@link Controller\ApiController} gives each the one answer a caller it
     * cannot verify gets — the entrance, and nothing past it. So a fresh clone, and every machine
     * that has not deliberately been given
     * a key, is in the safe state rather than the open one — the opposite of the site gate, where
     * absence stands the gate *down*. Worth reading twice, because the two files look alike and mean
     * opposite things.
     *
     * Untracked and excluded from a full deploy, like {@link self::Admin}'s live hashes: each
     * deployment holds its own key, which is what binds a payload to a deployment without any field
     * in the manifest naming one. Uploaded by hand, once. The private half never touches the
     * repository at all — it lives outside it entirely, as any token a tool signs with should.
     */
    case UpdateKey = 'update.pub';

    /**
     * The key sessions are sealed with — thirty-two random bytes, base64. See
     * {@link Http\SessionSeal}.
     *
     * Per deployment like {@link self::UpdateKey}, minted on the host it serves, never committed and
     * never deployed: a key that travelled from a laptop would seal the live site's sessions with a
     * secret the laptop still holds. A site that keeps no session never has one, and nothing asks
     * for it until something keeps a session.
     */
    case SessionKey = 'session.key';

    /**
     * Only the admin placeholder is tracked: the site gate's file exists per deployment and is
     * gitignored, and the two keys are untracked because each deployment holds its own.
     *
     * @return bool
     */
    public function isTracked(): bool
    {
        return match ($this) {
            self::Admin                                       => true,
            self::SiteAuth, self::UpdateKey, self::SessionKey => false,
        };
    }
}
