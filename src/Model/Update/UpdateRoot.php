<?php

declare(strict_types=1);

namespace Phpanta\Model\Update;

/**
 * The UpdateRoot enum. The four things a signed payload may write, and where each one lands.
 *
 * **This enum is the allowlist.** A member name whose first segment is not one of these values —
 * and `data` conspicuously is not — reaches no destination at all, which is what keeps
 * `data/admin.php`, `data/update.pub` and whatever else a site keeps in `data/` out of reach
 * of any push, however well signed. That is a stronger guarantee than a path check, because there
 * is no destination to compute rather than a destination that is computed and then rejected.
 *
 * `data/` is left to a full deploy for a second reason as well: it is the one tree copied *without*
 * deleting, because a site may keep gitignored files there that exist only on the server, and a
 * mirror from a clone that never had them would take them off it. A mirroring updater would have
 * to reproduce that asymmetry, and an updater with two opposite deletion policies in one code path
 * is where the mistake would live.
 *
 * **Where each root lands is deliberately not here.** That is {@link Deployment}'s question, and
 * separating the two is what stops a name's membership depending on the environment — see
 * {@link self::isTree()}, which is where that mistake is written down.
 */
enum UpdateRoot: string
{
    /** The webroot: `public/…` in the payload, whatever Apache calls it on the far side. */
    case Public = 'public';

    /** The application, one level above the webroot. The payload's prefix and the server's name agree here. */
    case Source = 'src';

    /**
     * The hand-rolled autoloader, which is a single file rather than a tree.
     *
     * It is a root of its own for the reason a full deploy ships it on its own: it sits
     * beside `src/` rather than inside it, and it is what `public/index.php` requires before any
     * class exists. Being one file is why {@link Deployment::directory()} answers null for it and why it
     * is never mirrored — a lone file is replaced or left alone, and can never be stale in the way
     * a tree can.
     */
    case Autoload = 'autoload.php';

    /**
     * The framework, beside `src/`: `phpanta/src/` and `phpanta/autoload.php`, and nothing else of
     * the repository it is vendored from — its tests, tools and docs are not deployed any more than
     * the site's are.
     *
     * **A root before anything ships into it**, which is the order that makes the first push of the
     * framework an ordinary one: a deployment whose updater already knows this root takes a payload
     * carrying it, where one that did not would refuse its first member. A root with no directory
     * on the server has nothing surplus in it, so a push that names none of it changes nothing.
     */
    case Framework = 'phpanta';

    /**
     * The root a payload member belongs to, or null if it belongs to none.
     *
     * Matches on the first path segment, or on the whole name for the single-file root. Null is the
     * refusal, and it is the *only* refusal this enum makes — everything else about a name is
     * {@link \Phpanta\Service\UpdateApplier}'s to check, before this is ever asked.
     *
     * **A tree root matches its own name as well as anything under it**, because an archive carries
     * a directory entry for `public/` before it carries `public/index.php`, and that entry is a
     * member like any other. A version of this that matched only the prefix refused every
     * well-formed payload at its first member, which is what the first end-to-end push did.
     *
     * @param string $name A payload member name, already validated as a safe relative path.
     * @return self|null
     */
    public static function of(string $name): ?self
    {
        foreach (self::cases() as $root) {
            if ($name === $root->value) {
                return $root;
            }

            if ($root->isTree() && str_starts_with($name, $root->value . '/')) {
                return $root;
            }
        }

        return null;
    }

    /**
     * Whether this root is a tree rather than a single file.
     *
     * **A fact about the vocabulary, answered without touching a filesystem**, which is the point:
     * deciding whether a name is under `public/` must not mean resolving where `public/` *is*.
     * Coupling a question about a string to a question about the environment lets a test that
     * points DOCUMENT_ROOT somewhere else reach the live tree, and the mirror deletes what it
     * reaches. Where a root lands is {@link Deployment}'s to say.
     *
     * @return bool
     */
    public function isTree(): bool
    {
        return $this !== self::Autoload;
    }
}
