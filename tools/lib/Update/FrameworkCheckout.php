<?php

declare(strict_types=1);

namespace Phpanta\Tool\Update;

use Phpanta\Support\Directory;

/**
 * The FrameworkCheckout class. Whether the framework a push would ship is the one the site records.
 *
 * A site vendors Phpanta as a git submodule at `phpanta/`, and a push packs `phpanta/src/` out of the
 * working tree — so it ships whatever is checked out there, which is not necessarily what the site's
 * commit says. Three ways that goes wrong, each silent until the server runs it:
 *
 * - **Not checked out at all.** A clone made without `--recurse-submodules` has an empty `phpanta/`,
 *   and the site's autoloader requires `phpanta/autoload.php` — a fatal on every page of a server
 *   that has no framework yet, and a framework the push never saw on one that has.
 * - **Edited and not committed.** Editing the framework in place is the point of vendoring it this
 *   way, and an edit that was never committed exists nowhere but on this machine and the server. An
 *   untracked file counts, and so does an ignored one: the push packs every file under `src/`,
 *   whatever git thinks of it.
 * - **Committed and not recorded.** A framework commit the site's HEAD does not record is on the
 *   server and in no commit of the site, so no checkout of the site reproduces what is deployed.
 *
 * A `phpanta/` that is not a submodule of the site's HEAD — copied in, freshly added and not yet
 * committed, or in a site that is not a git repository — has nothing to be compared with, and
 * passes. **A git that cannot answer does not pass**: git missing from the path, a repository it
 * refuses to read as "dubious ownership", a `.git` that points nowhere. Each of those used to read as
 * "not a submodule" and let anything through. `push-update --any-framework` is the deliberate way
 * past a refusal.
 */
final readonly class FrameworkCheckout
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Directory $root The site: the directory holding `autoload.php` and `phpanta/`.
     */
    public function __construct(private Directory $root) {}

    /**
     * Why a push should not ship the framework as it stands — or null, if it may.
     *
     * @return string|null
     */
    public function refusal(): ?string
    {
        $framework = $this->root->directory('phpanta');

        if (!$framework->file('autoload.php')->exists()) {
            return 'phpanta/ is not checked out — run `git submodule update --init`.';
        }

        // Not a repository at all: nothing records a framework, so there is nothing to disagree with.
        if (!file_exists($this->root->path . '/.git')) {
            return null;
        }

        if ($this->git($this->root, 'rev-parse', '--git-dir') === null) {
            return 'git cannot read this repository, so which framework it records cannot be checked'
                . ' — run `git status` to see why.';
        }

        $recorded = $this->recorded();
        if ($recorded === null) {
            return null;
        }

        // --ignored, because the push packs every file under src/ and git's opinion of a file is not
        // the push's: a stray `*.orig` a global excludes file hides would still ship.
        $changes = $this->git($framework, 'status', '--porcelain', '--ignored', '--', 'src', 'autoload.php');
        if ($changes === null) {
            return 'phpanta/ is a submodule git cannot read — run `git -C phpanta status` to see why.';
        }
        if ($changes !== '') {
            return "phpanta/ has changes that are not committed:\n" . $changes . "\n"
                . '  Commit them in phpanta/, then record the commit in the site (`git add phpanta`).';
        }

        $checkedOut = $this->git($framework, 'rev-parse', 'HEAD');
        if ($checkedOut !== $recorded) {
            return sprintf(
                'phpanta/ is at %s, and the site\'s HEAD records %s — record the checkout (`git add'
                . ' phpanta` and commit), or check out the recorded one (`git submodule update`).',
                substr((string) $checkedOut, 0, 7),
                substr($recorded, 0, 7),
            );
        }

        return null;
    }

    /**
     * The commit the site's HEAD records for `phpanta/`, or null when it records no submodule there —
     * including a repository with no commit yet, which records nothing.
     *
     * @return string|null
     */
    private function recorded(): ?string
    {
        $entry = $this->git($this->root, 'ls-tree', 'HEAD', '--', 'phpanta');

        if ($entry === null || preg_match('/^160000 commit ([0-9a-f]{40,64})\t/', $entry, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * What git prints for $arguments, run in $in — or null if it could not be run or failed.
     *
     * An array command, so nothing passes through a shell; standard error is discarded, because a
     * failure is answered by what the caller asks next rather than by git's wording.
     *
     * @param Directory $in
     * @param string ...$arguments
     * @return string|null
     */
    private function git(Directory $in, string ...$arguments): ?string
    {
        $process = proc_open(
            ['git', '-C', $in->path, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        if ($process === false) {
            return null;
        }

        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return proc_close($process) === 0 ? rtrim($out, "\n") : null;
    }
}
