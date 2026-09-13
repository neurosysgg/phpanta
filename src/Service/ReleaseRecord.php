<?php

declare(strict_types=1);

namespace Phpanta\Service;

use NoDiscard;
use Phpanta\Exception\UpdateException;
use Phpanta\Model\Update\Deployment;
use Phpanta\Model\Update\PreviousRelease;
use Phpanta\Model\Update\RecordEntry;
use Phpanta\Support\Collection;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\Directory;
use Phpanta\Support\File;

/**
 * The ReleaseRecord class. The one directory where a push keeps the release it replaced, so that
 * `update v1 rollback` can put it back.
 *
 * **It is a record, not a staging area**, and the difference is the one {@link UpdateApplier}'s
 * docblock draws: a push still writes straight into the live tree, file by file, and there is still
 * nothing to recover from a half-run because nothing runs half-way. What this adds is the other
 * direction — the bytes a push overwrites or deletes, copied aside *before* it does either, and the
 * names it adds, so that one step back is a signed request rather than a full deploy from an old
 * checkout.
 *
 * **Only the last release is kept.** Each push clears the record and takes a new one; each
 * completed rollback clears it, so the same rollback cannot run twice.
 *
 * Where it lives is {@link Deployment::previousRelease()}'s to say — beside the update serial,
 * above the webroot and in none of the roots, so no payload can name it and no mirror can reach it.
 * Inside it are the index ({@link PreviousRelease}) and `saved/`, which holds each saved file under
 * its payload name.
 *
 * **Three rules, each the mirror's own, applied to this directory:**
 *
 * - **Creating it is {@link Directory}'s job, done here deliberately** — {@link File} never creates
 *   one, and nothing else should create this one.
 * - **Clearing it is an enumerated delete of what its own index lists.** Never a walk, never
 *   {@link Directory::remove()}: a file here that the index does not name is not this class's to
 *   delete, and an index it cannot read is a record it refuses to delete around.
 * - **It never goes through a symbolic link** — not the directory itself, not any directory under
 *   `saved/`, not a saved copy. Nothing this class writes is a link, so one here was placed by
 *   something else, and following it would let a push write, or a rollback read, outside the
 *   deployment.
 */
final readonly class ReleaseRecord
{
    /** The index's file name. */
    private const string INDEX = 'release';

    /** The directory saved copies live under, each at its payload name. */
    private const string SAVED = 'saved';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Directory $directory Where the record lives. {@link Deployment::previousRelease()}.
     */
    public function __construct(private Directory $directory) {}

    /**
     * The record here, or null where there is none.
     *
     * @return PreviousRelease|null
     *
     * @throws UpdateException if there is an index and it cannot be read — through a link, unreadable,
     *                         or not one {@link PreviousRelease} writes.
     */
    public function read(): ?PreviousRelease
    {
        $index = $this->index();

        if (is_link($this->directory->path) || is_link($index->path)) {
            throw new UpdateException(sprintf(
                '%s is reached through a symbolic link, and the record is never read through one',
                $index->path,
            ));
        }

        if (!$index->exists()) {
            return null;
        }

        $text = $index->read();

        if ($text === null) {
            throw new UpdateException(sprintf('%s is there and cannot be read', $index->path));
        }

        return PreviousRelease::parse($text);
    }

    /**
     * Replaces the record here with one of $entries, saving from $deployment the bytes of every entry
     * that keeps a copy.
     *
     * The order is the design. The old record is cleared first; the new index is written marked
     * incomplete, naming everything this will save; each copy is saved and checked against the digest
     * it was recorded under; only then is the index written again, complete. A failure at any step
     * clears what was begun, by that same index, and says what failed.
     *
     * @param int|null $serial The serial of the push taking the record.
     * @param Collection<RecordEntry> $entries
     * @param Deployment $deployment Where the bytes to save are read from.
     * @return string|null Why the record could not be taken, or null where it was — completely.
     */
    #[NoDiscard(
        'a record that could not be taken is a push that could not be undone; dropping the answer pushes anyway',
    )]
    public function take(?int $serial, Collection $entries, Deployment $deployment): ?string
    {
        if (is_link($this->directory->path)) {
            return sprintf(
                '%s is a symbolic link, and the record is never written through one',
                $this->directory->path,
            );
        }

        if (!$this->directory->create(0o700)) {
            return sprintf('%s could not be created', $this->directory->path);
        }

        $cleared = $this->clear();

        if ($cleared !== null) {
            return 'the record it replaces could not be cleared: ' . $cleared;
        }

        $release = new PreviousRelease($serial, false, $entries);

        if (!$this->index()->write($release->render(), 0o600)) {
            return sprintf('%s could not be written', $this->index()->path);
        }

        foreach ($release->saved() as $entry) {
            $failed = $this->save($entry, $deployment);

            if ($failed !== null) {
                return $this->abandoned($failed);
            }
        }

        if (!$this->index()->write($release->completed()->render(), 0o600)) {
            return $this->abandoned(sprintf('%s could not be completed', $this->index()->path));
        }

        return null;
    }

    /**
     * The saved bytes of $entry, or null where there is no copy that can be trusted.
     *
     * Checked against the digest the entry was recorded under every time it is asked, so a copy
     * that was truncated, edited or replaced since is never mistaken for the one that was saved.
     *
     * @param RecordEntry $entry
     * @return string|null
     */
    public function saved(RecordEntry $entry): ?string
    {
        $bytes = $this->copyOf($entry)?->read();

        return $bytes !== null && RecordEntry::digest($bytes) === $entry->before ? $bytes : null;
    }

    /**
     * Removes the record: every copy its index lists, the directories under `saved/` that leaves
     * empty, then the index.
     *
     * **Enumerated, never walked.** What is deleted is exactly what the index names, each by the
     * path this class would have saved it at, and a directory is removed with `rmdir()`, which
     * refuses one that is not empty — so a file here that nothing listed survives, and the question
     * "is it empty" and the removal are one call that cannot disagree. The index goes last, so a
     * clear that stops halfway leaves an index that still lists what is left.
     *
     * @return string|null Why the record could not be cleared, or null where there is none left.
     */
    #[NoDiscard('a record that could not be cleared can be rolled back to again; dropping the answer hides it')]
    public function clear(): ?string
    {
        try {
            $release = $this->read();
        } catch (UpdateException $unreadable) {
            return $unreadable->getMessage() . ' — so what it holds cannot be enumerated, and nothing was deleted';
        }

        if ($release === null) {
            return null;
        }

        $saved       = $this->directory->directory(self::SAVED)->path;
        $directories = [];

        foreach ($release->saved() as $entry) {
            $copy = $this->copyOf($entry);

            if ($copy === null) {
                return sprintf("the saved copy of '%s' is reached through a symbolic link", $entry->name);
            }

            if (!$copy->delete()) {
                return sprintf("the saved copy of '%s' could not be removed", $entry->name);
            }

            $directory = dirname($copy->path);

            while (strlen($directory) >= strlen($saved)) {
                $directories[$directory] = substr_count($directory, '/');
                $directory               = dirname($directory);
            }
        }

        // Deepest first, so a directory whose only contents were emptied directories goes too.
        arsort($directories);

        foreach ($directories as $directory => $depth) {
            Diagnostics::muted(static fn(): bool => rmdir($directory));
        }

        return $this->index()->delete() ? null : sprintf('%s could not be removed', $this->index()->path);
    }

    /**
     * Saves the live bytes of $entry, provided they are still the bytes it was recorded under.
     *
     * @param RecordEntry $entry
     * @param Deployment $deployment
     * @return string|null Why it could not be saved, or null where it was.
     */
    private function save(RecordEntry $entry, Deployment $deployment): ?string
    {
        $live = $deployment->destination($entry->root, $entry->name);

        // Read twice — once to record the digest, once here to save — and the second read is held
        // to the first, so a file that changed in between is a failure rather than a copy of bytes
        // the index does not describe.
        $bytes = is_link($live->path) ? null : $live->read();

        if ($bytes === null || RecordEntry::digest($bytes) !== $entry->before) {
            return sprintf("'%s' could not be read, or changed while it was being recorded", $entry->name);
        }

        $copy = $this->copyOf($entry);

        if ($copy === null) {
            return sprintf("'%s' would be saved through a symbolic link", $entry->name);
        }

        if (!$copy->directory()->create(0o700) || !$copy->write($bytes, 0o600)) {
            return sprintf("'%s' could not be saved", $entry->name);
        }

        return null;
    }

    /**
     * $failed, once what the incomplete record began has been cleared — and what could not be, if
     * anything could not.
     *
     * @param string $failed
     * @return string
     */
    private function abandoned(string $failed): string
    {
        $left = $this->clear();

        return $left === null ? $failed : $failed . '; and what it had begun could not be cleared: ' . $left;
    }

    /**
     * Where $entry's saved copy lives, or null where any step of the way there is a symbolic link.
     *
     * Every step is asked, from `saved/` down to the copy itself: `mkdir -p` and `rename()` both go
     * through a link to a directory without a word, so a link anywhere on the path is a copy that
     * would land, or be read, somewhere else entirely.
     *
     * @param RecordEntry $entry
     * @return File|null
     */
    private function copyOf(RecordEntry $entry): ?File
    {
        $path = $this->directory->directory(self::SAVED)->path;

        if (is_link($this->directory->path) || is_link($path)) {
            return null;
        }

        foreach (explode('/', $entry->name) as $segment) {
            $path .= '/' . $segment;

            if (is_link($path)) {
                return null;
            }
        }

        return new File($path);
    }

    /**
     * @return File
     */
    private function index(): File
    {
        return $this->directory->file(self::INDEX);
    }
}
