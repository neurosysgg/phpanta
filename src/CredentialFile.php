<?php

declare(strict_types=1);

namespace Phpanta;

/**
 * The CredentialFile enum. The seven files in `data/` the framework itself reads: the admin gate's
 * credentials, the admin's signing key, the key sessions are sealed with, the devices that may
 * open the admin in a browser, what the admin may do to the machine it runs on, and whether it keeps
 * drops and the key it seals them under.
 *
 * **Every one of them changes what the site does rather than what it shows**, which is why they
 * are the framework's rather than a site's: the admin gate, the signed API and the admin's browser
 * sessions are framework code, and each is switched by whether its file is there.
 *
 * **Every one of them fails closed**, and that is the arrangement to keep. A misspelled case reads
 * as "no such file", so absence must never be the open state: without its file the admin gate
 * refuses loudly, no signed call verifies, no session opens and no device is enrolled.
 *
 * All seven are per deployment, so a full deploy excludes all seven; each that a deployment needs is
 * uploaded, minted, enrolled or written by hand.
 */
enum CredentialFile: string implements DataFileName
{
    /** bcrypt credentials for the admin gate — per deployment, for a site with a route behind it. */
    case Admin = 'admin.php';

    /**
     * The ECDSA public key the admin verifies every signed call against — the public half, and only
     * ever that.
     *
     * **Its absence is the off switch, and off is closed.** No key file, no signed call verifies:
     * {@link Service\ApiGate} refuses every request, and {@link Controller\ApiController} gives each
     * the one answer a caller it cannot verify gets — the entrance, and nothing past it. So a fresh
     * clone, and every machine that has not deliberately been given a key, is in the safe state
     * rather than the open one.
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
     * The devices that may open the admin in a browser — each a passkey's public half. See
     * {@link Service\Passkey\PasskeyRegistry}.
     *
     * **Absent is none**, with {@link self::UpdateKey}'s polarity: no file, no device, and a browser
     * sees the entrance and nothing past it. Per deployment, written only by the signed `access v1
     * enrol` and `revoke`, and never deployed — a copy from a laptop would put the laptop's list of
     * devices on the live host.
     */
    case AdminPasskeys = 'admin-passkeys.json';

    /**
     * Whether the admin's `machine` service is on, and what it reaches: the roots it may walk, and
     * whether it may write there or run a command. See {@link Model\Machine\MachineConfig}.
     *
     * **Absent is off**, with {@link self::UpdateKey}'s polarity: no file, no service, and the
     * admin's listings do not name it. A file that does not read is off too. Per deployment and never
     * deployed — a copy from a laptop would open the live host's filesystem to its admin.
     */
    case Machine = 'machine.json';

    /**
     * Whether the `drop` service is on, and how much it keeps for how long. See
     * {@link Model\Drop\DropConfig}.
     *
     * **Absent is off**, with {@link self::Machine}'s polarity: no file, no service — no listing names
     * it, and `/drop` answers as an address that is not there. Per deployment and never deployed.
     */
    case Drop = 'drop.json';

    /**
     * The key every drop is sealed under beside its link — thirty-two random bytes, base64. See
     * {@link Service\Drop\DropCipher}.
     *
     * Per deployment like {@link self::SessionKey}, minted on the host it serves and never deployed.
     * **Removing it is the quickest way to destroy every drop at once**: without it no drop opens,
     * whoever holds a link.
     */
    case DropKey = 'drop.key';

    /**
     * None is tracked: each holds what one deployment holds — a credential, a key, a list of
     * devices — so a public repository cannot publish it, and `health v1 deployment` requires none.
     *
     * @return bool
     */
    public function isTracked(): bool
    {
        return false;
    }
}
