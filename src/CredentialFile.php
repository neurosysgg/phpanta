<?php

declare(strict_types=1);

namespace Phpanta;

/**
 * The CredentialFile enum. The three files in `data/` the framework itself reads: the two gates'
 * credentials and the API's key.
 *
 * **Every one of them changes what the site does rather than what it shows**, which is why they
 * are the framework's rather than a site's: the site gate, the admin gate and the signed API are
 * framework code, and each is switched by whether its file is there. Two of the three are switched
 * in opposite directions, and that is the thing to read twice — see {@link self::SiteAuth} and
 * {@link self::UpdateKey}.
 *
 * All three hold live credentials, so `deploy.sh` excludes all three and each is uploaded by hand.
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
     * The ECDSA public key `/api` verifies every signed call against — the public half, and only
     * ever that.
     *
     * **Its absence is the off switch, which is {@link self::SiteAuth}'s arrangement with the
     * polarity reversed.** No key file, no endpoint: {@link Service\ApiGate} refuses every
     * request and {@link Controller\ApiController} answers exactly as the site answers for a
     * path no route claims. So a fresh clone, and every machine that has not deliberately been given
     * a key, is in the safe state rather than the open one — the opposite of the site gate, where
     * absence stands the gate *down*. Worth reading twice, because the two files look alike and mean
     * opposite things.
     *
     * Untracked and excluded from `deploy.sh`, like {@link self::Admin}'s live hashes: each
     * deployment holds its own key, which is what binds a payload to a deployment without any field
     * in the manifest naming one. Uploaded by hand, once. The private half never touches the
     * repository at all — it lives outside it entirely, the way the SoundCloud refresh token does.
     */
    case UpdateKey = 'update.pub';

    /**
     * Only the admin placeholder is tracked: the site gate's file exists per deployment and is
     * gitignored, and the key is untracked because each deployment holds its own.
     *
     * @return bool
     */
    public function isTracked(): bool
    {
        return match ($this) {
            self::Admin                     => true,
            self::SiteAuth, self::UpdateKey => false,
        };
    }
}
