<?php

declare(strict_types=1);

namespace Phpanta\Model\Update;

use Phpanta\App;
use Phpanta\Support\Directory;
use Phpanta\Support\File;

/**
 * The Deployment class. The two directories a push may write into, and the mapping from a payload
 * member's name to the file it becomes.
 *
 * **This is separate from {@link UpdateRoot} because membership must not require resolving a
 * path.** An enum that reached for {@link App::webroot()} to decide so much as whether a name
 * is *under* a root would give a test that points `DOCUMENT_ROOT` at a sandbox a sandbox for one
 * root and the live tree for the other — and the mirror deletes what it reaches.
 *
 * The split is the ordinary one: `UpdateRoot` is the **vocabulary** — three names,
 * asked and answered without touching a filesystem — and this is the **environment**, which is a
 * value a caller holds rather than a static reached through. So a test constructs one over a
 * sandbox and cannot reach anything else, and production constructs {@link self::current()} and
 * cannot reach a sandbox.
 */
final readonly class Deployment
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Directory $above Where `src/` and `autoload.php` live.
     * @param Directory $webroot Where `public/` lands, under whatever name the server calls it.
     */
    public function __construct(private Directory $above, private Directory $webroot) {}

    /**
     * The deployment this request is running in.
     *
     * @return self
     */
    public static function current(): self
    {
        return new self(App::current()->above(), App::current()->webroot());
    }

    /**
     * Where a root's tree lives, or null when the root is a single file.
     *
     * @param UpdateRoot $root
     * @return Directory|null
     */
    public function directory(UpdateRoot $root): ?Directory
    {
        return match ($root) {
            UpdateRoot::Public   => $this->webroot,
            UpdateRoot::Source    => $this->above->directory($root->value),
            UpdateRoot::Framework => $this->above->directory($root->value),
            UpdateRoot::Autoload  => null,
        };
    }

    /**
     * The absolute destination for a payload member.
     *
     * The payload's prefix is stripped and the root's own directory put in its place, which for
     * {@link UpdateRoot::Source} is the identity and for {@link UpdateRoot::Public} is the whole
     * point — the directory is `public/` in the repository and whatever the host names its document
     * root on the server.
     *
     * @param UpdateRoot $root
     * @param string $name A member name that root claimed.
     * @return File
     */
    public function destination(UpdateRoot $root, string $name): File
    {
        $directory = $this->directory($root);

        return $directory === null
            ? $this->above->file($root->value)
            : $directory->file(substr($name, strlen($root->value) + 1));
    }

    /**
     * Where the record of the previous release is kept — see {@link \Phpanta\Service\ReleaseRecord}.
     *
     * Beside the update serial, and for the serial's reasons: above the webroot, so nothing serves
     * it; in none of the roots, so no payload can name it and no mirror walks it; and outside
     * `data/`, which a site deploys from whichever machine deployed last. Derived here rather than
     * on the app because a test holds a Deployment over a sandbox, and the record must be as
     * unreachable from a test as the tree it records.
     *
     * @return Directory
     */
    public function previousRelease(): Directory
    {
        return $this->above->directory('.update-previous');
    }

    /**
     * The payload name a file inside a root's tree would have been packed under.
     *
     * The inverse of {@link self::destination()}, and it exists for the mirror: the applier walks
     * what is on disk and has to ask whether the payload named it. Written beside its inverse so
     * the two halves of one mapping cannot drift.
     *
     * @param UpdateRoot $root
     * @param string $path An absolute path inside {@link self::directory()}.
     * @return string
     */
    public function nameOf(UpdateRoot $root, string $path): string
    {
        $directory = $this->directory($root);

        return $directory === null
            ? $root->value
            : $root->value . '/' . ltrim(substr($path, strlen($directory->path)), '/');
    }
}
