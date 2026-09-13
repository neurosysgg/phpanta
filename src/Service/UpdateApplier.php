<?php

declare(strict_types=1);

namespace Phpanta\Service;

use NoDiscard;
use Phpanta\Exception\UpdateException;
use Phpanta\Model\Update\Deployment;
use Phpanta\Model\Update\RecordEntry;
use Phpanta\Model\Update\RecordKind;
use Phpanta\Model\Update\RollbackStep;
use Phpanta\Model\Update\UpdateFile;
use Phpanta\Model\Update\UpdateManifest;
use Phpanta\Model\Update\UpdateReport;
use Phpanta\Model\Update\UpdateRoot;
use Phpanta\Support\BareArray;
use Phpanta\Support\Collection;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\SearchableCollection;
use Phpanta\Support\TarArchive;
use Phpanta\Support\TarEntry;

/**
 * The UpdateApplier class. Turns a verified archive into files on disk, and removes what the
 * archive omits.
 *
 * **Everything is validated before anything is written.** The archive is expanded and every member
 * checked in memory first, so a payload with one bad name writes nothing at all rather than the
 * files that happened to come before it. That is affordable because the payload is small — a few
 * hundred kilobytes compressed, under a megabyte expanded, against {@link self::MAX_EXPANDED} — and
 * it is what makes the rest of this worth doing.
 *
 * **Then everything that changes is staged, and only then does anything live change.** Each file
 * whose bytes differ is written into {@link Deployment::stage()}, beside the roots — never under
 * `sys_get_temp_dir()`, which `update v1 probe` measured on another device, where a rename is a
 * copy. Staging also asks the live tree whether each destination can take a file at all, so a
 * directory in the way, or a file where a directory must be, refuses the whole push before a live
 * byte moves; before this, it failed at write time with the files ahead of it already landed. Only
 * once every file is staged is the release recorded and each file renamed onto its live path
 * ({@link File::moveOnto()}), in pack order. Each rename is atomic, so no name is ever missing or
 * half-written, and the window left between the first and the last is a burst of renames rather
 * than a run of writes. What can still fail there is what staging cannot ask — a live directory
 * this process may not write into, a disk that filled in between — and that is reported per path,
 * as before. The whole trees are not swapped: two directory renames leave a name with nothing at it
 * for a measured 626 µs on the live host, and a push has four roots.
 *
 * **The mirror is an enumerated delete, never a recursive one.** What is on disk is walked, diffed
 * against the payload, and each surplus path is checked by the same rules an added path passes
 * before {@link File::delete()} is called on it — one named file at a time. It walks **only the roots
 * the payload carries**: a push with nothing under `phpanta/` says nothing about `phpanta/`, and
 * reading that silence as "delete all of it" is how a push from a clone without the framework
 * checked out would take the framework off the server. And it runs **only after every write
 * succeeded**, because deleting the old half of a change whose new half did not land leaves neither.
 *
 * **Before a push lands anything, it records what it is about to replace** — not the stage, but a
 * copy of the other direction: the bytes of every
 * file it will overwrite or the mirror will delete, and the name of every file it will add. See
 * {@link ReleaseRecord}. A record that cannot be taken completely refuses the push with nothing live
 * written, since a push that cannot be taken back is one the operator did not ask for. And
 * {@link self::rollback()} puts that release back, one step and no further.
 *
 * **This class is generous with detail, unlike everything in {@link ApiGate}.** Every refusal
 * below says exactly what was wrong, because nothing reaches here without having produced a valid
 * signature first. It is also the only account of the run there will be: on a host with
 * `display_errors` off and no `error_log`, a warning goes nowhere at all.
 */
final readonly class UpdateApplier
{
    /**
     * The most bytes an archive may expand to.
     *
     * The body is capped by {@link ApiGate::MAX_BODY} before it is read, and this caps what that
     * body becomes — without it, an eight-megabyte archive of zeros expands until the process dies
     * of `memory_limit`, a bare 500 with the serial already spent. Twice the body's cap, which is
     * about twenty times what a real tree expands to; the memory floor
     * {@link \Phpanta\Support\RequirementInitialization} declares is derived from it.
     */
    public const int MAX_EXPANDED = 2 * ApiGate::MAX_BODY;

    /**
     * A member name this class will write.
     *
     * Deliberately narrower than "a path without `..` in it". Only these characters, no leading
     * slash, no empty segment, no backslash, nothing outside printable ASCII. Everything the
     * repository actually contains passes; a name that would need `realpath()` to reason about is
     * refused rather than resolved, which is why nothing downstream carries a traversal guard and
     * why nothing downstream needs one.
     */
    private const string SAFE_NAME = '#\A[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\z#';

    /**
     * The name the Linux NFS client gives a file it renamed aside: `.nfs`, then 24 hexadecimal
     * digits — the inode's file id and a counter.
     *
     * **That name is the client's, not the site's.** It appears when a file some process still holds
     * open is renamed over or unlinked, lives exactly as long as the handle does, and is removed by
     * the client itself when the handle closes. So a push neither writes nor deletes one as its own:
     * {@link self::rooted()} refuses the name in a payload, the mirror leaves it out of what is
     * surplus, and {@link self::mirror()} tries it once on the way past and says in a note what came
     * of that — never a failure, since what holds it is a worker the push cannot reach. Public for
     * {@link FilesystemProbe}, which counts them.
     */
    public const string NFS_STRAY = '#\A\.nfs[0-9a-f]{24}\z#';

    /** ustar's own limit, and a bound on how deep any of this can go. */
    private const int MAX_NAME = 255;

    /** Who holds a member name, in the sentence a refused one is reported in. */
    private const string ARCHIVE = 'the archive';

    /** The one sentence every refusal to record the previous release opens with. */
    private const string UNRECORDED = 'the previous release could not be recorded, so nothing was written: %s';

    /** The sentence a push that could not stage what it writes is refused with. */
    private const string UNSTAGED = 'the push could not be staged, so nothing was written: %s';

    /** The sentence a rollback that could not stage what it restores is refused with. */
    private const string UNSTAGED_ROLLBACK = 'the rollback could not be staged, so nothing was restored or removed: %s';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Deployment|null $deployment Where the roots land. **Not a convenience seam**: it is
     *                                    what makes a test physically unable to reach the live
     *                                    tree. Before it existed, a test that meant to write into a
     *                                    sandbox resolved one root to the sandbox and the other to
     *                                    this repository, and the mirror emptied the second.
     */
    public function __construct(private ?Deployment $deployment = null) {}

    /**
     * Applies $archive, reporting what it did.
     *
     * @param string $archive The gzipped tar, already verified against a signed digest.
     * @param UpdateManifest $manifest
     * @param int|null $serial The serial of the push, kept in the record of the release it replaces.
     * @return UpdateReport
     *
     * @throws UpdateException if the archive cannot be expanded, holds a member this class will not
     *                         write, or the release it replaces cannot be recorded. Nothing live
     *                         has been written when this throws.
     */
    #[NoDiscard('the report is the endpoint\'s entire response; dropping it sends an empty 200')]
    public function apply(string $archive, UpdateManifest $manifest, ?int $serial = null): UpdateReport
    {
        // Decoded under a cap rather than whole. The cap is only as fine as zlib's output buffer —
        // gzdecode() hands back a result a chunk past it rather than refusing — so the length is
        // asked as well. It answers false for an archive far past the cap exactly as it does for
        // one that is not gzip, so the sentence names both.
        $tar = Diagnostics::muted(static fn(): string|false => gzdecode($archive, self::MAX_EXPANDED));
        if ($tar === false || strlen($tar) > self::MAX_EXPANDED) {
            throw new UpdateException(sprintf(
                'the update archive is not gzip, is corrupt, or expands past %d bytes',
                self::MAX_EXPANDED,
            ));
        }

        // Names are checked before the deployment is resolved, so a payload this class would refuse
        // is refused on a machine that has no webroot at all — which is every CLI run.
        $files = $this->validated(TarArchive::parse($tar));

        $deployment = $this->deployment ?? Deployment::current();

        if (!$manifest->apply) {
            return $this->planned($files, $manifest, $deployment)->dryRun();
        }

        $changed   = new Collection(UpdateFile::class);
        $unchanged = new Collection(UpdateFile::class);

        foreach ($files as $file) {
            if ($this->isCurrent($file, $deployment)) {
                $unchanged = $unchanged->with($file);
            } else {
                $changed = $changed->with($file);
            }
        }

        // Staged before the record is taken and before a live byte moves, so a push that cannot
        // stage refuses having changed nothing — not the tree, and not the record of the last push
        // that did land. Then the record, the last thing that may refuse; then the landing.
        $left = $this->staged($changed, $deployment, self::UNSTAGED);

        try {
            $report = $this->record($files, $manifest, $deployment, $serial);
        } catch (UpdateException $refused) {
            // A push the record refuses lands nothing, so what it staged is no use to anybody.
            $this->cleared($deployment->stage());

            throw $refused;
        }

        foreach ($unchanged as $file) {
            $report = $report->kept($file->name);
        }

        $report = $this->landed($changed, $deployment, $left === null ? $report : $report->noted($left));

        if (!$changed->isEmpty()) {
            $report = $report->noted(sprintf(
                'staged %d files beside the roots, then renamed them into place',
                $changed->count(),
            ));
        }

        if (!$manifest->mirror) {
            return $report;
        }

        // A run that could not write everything deletes nothing. The mirror removes the old half of
        // a change on the understanding that the new half is already there, and a failed write is
        // exactly the case where it is not — a stylesheet that did not land, its predecessor then
        // deleted, and a site serving neither. The report names what failed; the next push, or
        // a full deploy, finishes the job with both halves still on disk.
        if (!$report->isComplete()) {
            return $report->noted('the mirror did not run, because a write failed — nothing was deleted');
        }

        return $this->mirror($files, $report, $deployment);
    }

    /**
     * Puts back the release the last push replaced, from the record it took, reporting what it did.
     *
     * **It moves a path from the state the push left to the state before it, and only from there.**
     * Each recorded path is weighed against both states first — see {@link RecordEntry::step()} —
     * and a single path in neither refuses the whole rollback before anything is written: a full
     * deploy or a hand edit since the push has made the tree something the record does not describe,
     * and restoring half of it over the other half would leave a deployment that is neither release.
     * A path already back in its earlier state — a write the push never landed — is left alone, by
     * the rule {@link self::isCurrent()} states for a push.
     *
     * Then, in dependency order: what the mirror deleted is recreated first, since nothing live
     * names it yet; what the push changed is restored in the order the push wrote it; and what it
     * added is removed last, only if every restore landed — the mirror's rule, for the mirror's
     * reason. Every restore is a {@link File::write()} of the very bytes that were proved against
     * their digest before the first one was written — read once, held, and written, so there is no
     * second read for a copy to change under.
     *
     * **A rollback that completed clears the record**, so the same rollback cannot run twice and a
     * second asks for a record that is not there. One that did not complete keeps it, and can be run
     * again once what failed is fixed; the paths it did restore are then in their earlier state and
     * are left alone.
     *
     * The request runs the code it is rolling back *from*, exactly as a push runs the code it
     * replaces, and for the same reason that is survivable: each file lands whole.
     *
     * @param bool $apply False for a dry run, which reports the same plan and writes nothing.
     * @return UpdateReport
     *
     * @throws UpdateException if there is no record, it is incomplete or unreadable, a saved copy is
     *                         missing or damaged, or the deployment has moved on since it was taken.
     *                         Nothing has been written when this throws.
     */
    #[NoDiscard('the report is the endpoint\'s entire response; dropping it sends an empty 200')]
    public function rollback(bool $apply): UpdateReport
    {
        $deployment = $this->deployment ?? Deployment::current();
        $record     = new ReleaseRecord($deployment->previousRelease());
        $release    = $record->read();

        if ($release === null) {
            throw new UpdateException(
                'there is no previous release to roll back to: no push has recorded one here, or the '
                . 'last rollback already put it back',
            );
        }

        if (!$release->complete) {
            throw new UpdateException(
                'the record of the previous release is incomplete — the push that began it was refused '
                . 'before it wrote anything, so there is nothing to roll back',
            );
        }

        $report    = new UpdateReport()->noted(sprintf('rolling back the push of serial %s', $release->serial ?? '-'));
        $recreate  = new Collection(RecordEntry::class);
        $restore   = new Collection(RecordEntry::class);
        $remove    = new Collection(RecordEntry::class);
        $conflicts = new Collection(RecordEntry::class);

        foreach ($release->entries as $entry) {
            $step = $entry->step(self::digestOf($deployment->destination($entry->root, $entry->name)));

            if ($step === RollbackStep::Restore && $entry->kind === RecordKind::Deleted) {
                $recreate = $recreate->with($entry);
            } elseif ($step === RollbackStep::Restore) {
                $restore = $restore->with($entry);
            } elseif ($step === RollbackStep::Remove) {
                $remove = $remove->with($entry);
            } elseif ($step === RollbackStep::Keep) {
                $report = $report->kept($entry->name);
            } else {
                $conflicts = $conflicts->with($entry);
            }
        }

        if (!$conflicts->isEmpty()) {
            throw new UpdateException(sprintf(
                'the deployment has changed since the push this record was taken for, so rolling it back '
                . 'would mix two releases, and nothing was restored or removed. Changed since: %s. A full '
                . 'deploy is the way back from here',
                $conflicts->map(static fn(RecordEntry $entry): string => $entry->name)->join(', '),
            ));
        }

        // Every copy is read and proved before the first one is written, and what is written is the
        // bytes that were proved — so a damaged record refuses rather than restoring the half that
        // happened to come first. They are held as UpdateFiles, a root, a name and the bytes to put
        // there, which is exactly what one is; the memory is at most what the push replaced, the
        // same order as the payload a push holds whole.
        $copies  = new Collection(UpdateFile::class);
        $damaged = new Collection(RecordEntry::class);

        foreach ($recreate->with(...$restore->toValues()) as $entry) {
            $bytes = $record->saved($entry);

            if ($bytes === null) {
                $damaged = $damaged->with($entry);
            } else {
                $copies = $copies->with(new UpdateFile($entry->root, $entry->name, $bytes));
            }
        }

        if (!$damaged->isEmpty()) {
            throw new UpdateException(sprintf(
                'the saved copy of %s is missing or no longer what was recorded, so nothing was restored or removed',
                $damaged->map(static fn(RecordEntry $entry): string => $entry->name)->join(', '),
            ));
        }

        if (!$apply) {
            foreach ($copies as $copy) {
                $report = $report->wrote($copy->name);
            }

            foreach ($remove as $entry) {
                $report = $report->removed($entry->name);
            }

            return $report->dryRun();
        }

        // Staged before anything is restored, for apply()'s reason: a restore that cannot be placed
        // refuses the rollback with the tree and the record exactly as they were.
        $left = $this->staged($copies, $deployment, self::UNSTAGED_ROLLBACK);

        return $this->undo($copies, $remove, $record, $deployment, $left === null ? $report : $report->noted($left));
    }

    /**
     * The root a file of this name falls under, by every rule a pushed member passes.
     *
     * It exists for the record of the previous release, whose index names paths a rollback then
     * writes and deletes: those names are held to exactly the rules the archive's are, in one
     * place, so the record can never be a second, weaker path to the tree.
     *
     * @param string $name
     * @param string $holder Who holds the name, for the sentence a refusal is reported in.
     * @return UpdateRoot
     *
     * @throws UpdateException if the name is one this class would not write.
     */
    public static function claim(string $name, string $holder): UpdateRoot
    {
        if ($name === '' || strlen($name) > self::MAX_NAME) {
            throw new UpdateException(
                sprintf('%s holds a name that is empty or over %d bytes', $holder, self::MAX_NAME),
            );
        }

        return self::rooted($name, false, $holder);
    }

    /**
     * Every regular file in the archive, with each name checked and each name appearing once.
     *
     * Directory members are dropped rather than carried: they say nothing the file names do not,
     * and {@link Directory::create()} makes parents anyway — keeping them would mean a second kind
     * of thing to validate and a second kind of thing to mirror. Their *names* are still checked,
     * because a refused name is worth refusing wherever it appears.
     *
     * Duplicates collapse to the last one, which is what tar itself means by a repeated member and
     * what an extractor does with it. It matters here for a smaller reason than correctness of
     * contents: a name written twice would be reported twice, and the report is the only record.
     *
     * @param Collection<TarEntry> $entries
     * @return Collection<UpdateFile>
     *
     * @throws UpdateException
     */
    private function validated(Collection $entries): Collection
    {
        $files = new SearchableCollection(UpdateFile::class);

        foreach ($entries as $entry) {
            // check() answers with the root rather than discarding it, which is what lets every
            // step below have one without asking a question that can be null. See UpdateFile.
            $root = $this->check($entry);

            if (!$entry->isDirectory) {
                $files = $files->with($entry->name, new UpdateFile($root, $entry->name, $entry->contents));
            }
        }

        // A name that is a file and also a directory some other file sits under is two members that
        // cannot both be written — whichever lands second fails, after the first has. Refused here,
        // before anything is written, because "passes every rule or writes nothing" is the promise.
        foreach ($files as $name => $file) {
            $ancestor = '';

            foreach (explode('/', dirname($name)) as $segment) {
                $ancestor = $ancestor === '' ? $segment : $ancestor . '/' . $segment;

                if ($files->find($ancestor) !== null) {
                    throw new UpdateException(sprintf(
                        "the archive holds '%s' as a file and as the directory '%s' is under",
                        $ancestor,
                        $file->name,
                    ));
                }
            }
        }

        return new Collection(UpdateFile::class)->with(...$files->toValues());
    }

    /**
     * Refuses a member name this class will not write, saying which rule it broke.
     *
     * **It takes the entry rather than the name, because two of the rules are about the pair.** A
     * name is only half of what a member is, and a regular file may not be named as a directory or
     * in place of one — see the two refusals that ask `isDirectory`, here and in
     * {@link self::rooted()}. Both are shapes no `tar` produces and neither could be reached without
     * the private key; they are refused because the alternative is a destination computed from
     * them, and the one thing this class promises is that a member either passes every rule or
     * writes nothing.
     *
     * @param TarEntry $entry
     * @return UpdateRoot The root it falls under. Returned rather than discarded so that no later
     *                    step has to ask again — the second asking is where a null appears that
     *                    this method has already made impossible.
     *
     * @throws UpdateException
     */
    private function check(TarEntry $entry): UpdateRoot
    {
        $name = rtrim($entry->name, '/');

        if ($name === '' || strlen($name) > self::MAX_NAME) {
            throw new UpdateException(sprintf(
                'the archive holds a member whose name is empty or over %d bytes',
                self::MAX_NAME,
            ));
        }

        // A regular file whose name ends in a slash is malformed tar, and refusing it is what keeps
        // the name this validates and the name UpdateFile carries the same string: the rtrim above
        // would otherwise check `public/x` while the write went to `public/x/`, which File::write()
        // cannot rename onto — reported as a failure rather than as the refusal it is.
        //
        // Asked *after* the emptiness check, deliberately: a name of `/` is both slash-terminated
        // and nothing at all, and "there is no name here" is the more useful of the two sentences.
        if (!$entry->isDirectory && str_ends_with($entry->name, '/')) {
            throw new UpdateException(sprintf(
                "the archive holds '%s' as a regular file, but that name is written as a directory",
                $entry->name,
            ));
        }

        return self::rooted($name, $entry->isDirectory, self::ARCHIVE);
    }

    /**
     * The rules a name must pass whoever holds it — the archive, or the record of the previous
     * release — and the root it falls under.
     *
     * @param string $name Already bounded in length, and without a trailing slash.
     * @param bool $isDirectory
     * @param string $holder
     * @return UpdateRoot
     *
     * @throws UpdateException
     */
    private static function rooted(string $name, bool $isDirectory, string $holder): UpdateRoot
    {
        if (preg_match(self::SAFE_NAME, $name) !== 1) {
            throw new UpdateException(sprintf(
                "%s holds '%s', which is not a plain relative path this site will write",
                $holder,
                $name,
            ));
        }

        // `.` and `..` are spelled with characters SAFE_NAME allows, so they pass the pattern and
        // are refused here instead. This is the check that would matter if the pattern were ever
        // widened, which is exactly why it is separate from it rather than folded in.
        foreach (explode('/', $name) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new UpdateException(sprintf("%s holds '%s', which walks the tree", $holder, $name));
            }

            // The mirror's rule turned round: a name the mirror will not delete as surplus is one no
            // payload may write, or a push could plant a file the next one could never take away.
            if (preg_match(self::NFS_STRAY, $segment) === 1) {
                throw new UpdateException(sprintf(
                    "%s holds '%s', whose name is the one the NFS client gives a file it renamed aside",
                    $holder,
                    $name,
                ));
            }
        }

        $root = UpdateRoot::of($name);

        if ($root === null) {
            throw new UpdateException(sprintf(
                "%s holds '%s', which is under none of the roots a push may write (%s)",
                $holder,
                $name,
                new Collection(UpdateRoot::class)
                    ->with(...UpdateRoot::cases())
                    ->map(static fn(UpdateRoot $root): string => $root->value)
                    ->join(', '),
            ));
        }

        // A tree root matches its own name as well as anything under it, because an archive carries
        // a directory entry for `public/` before the files in it — see UpdateRoot::of(). That is
        // right for a directory member and wrong for a regular file: `Deployment::destination()`
        // strips the prefix and one separator, so a *file* called `public` resolves to `substr()`
        // of nothing and names the webroot directory itself. Nothing would be overwritten — the
        // rename fails on a directory — but it would be reported as a write that failed rather than
        // as a payload that was never legal.
        if (!$isDirectory && $root->isTree() && $name === $root->value) {
            throw new UpdateException(sprintf(
                "%s holds '%s' as a regular file, but that name is a tree this push writes "
                . 'into rather than a file it writes',
                $holder,
                $name,
            ));
        }

        return $root;
    }

    /**
     * What a run would have done, without doing it.
     *
     * @param Collection<UpdateFile> $files
     * @param UpdateManifest $manifest
     * @param Deployment $deployment
     * @return UpdateReport
     */
    private function planned(Collection $files, UpdateManifest $manifest, Deployment $deployment): UpdateReport
    {
        $report = new UpdateReport();

        foreach ($files as $file) {
            $report = $this->isCurrent($file, $deployment)
                ? $report->kept($file->name)
                : $report->wrote($file->name);
        }

        if (!$manifest->mirror) {
            return $report;
        }

        foreach (UpdateRoot::cases() as $root) {
            if (!self::carries($files, $root)) {
                $report = self::untouched($report, $root, $deployment);
                continue;
            }

            foreach ($this->surplusIn($root, $files, $deployment) as $name) {
                $report = $report->removed($name);
            }
        }

        return $report;
    }

    /**
     * Records the release this push is about to replace, answering the report the push begins with.
     *
     * **What is recorded is what the push will change, and nothing it will not.** A file whose
     * bytes are already there is neither saved nor listed — the rule {@link self::isCurrent()}
     * states, which keeps a push from touching a file it is not changing, and keeps a rollback from
     * touching it either. A file about to be overwritten is saved; one about to be written where
     * there was none is listed as added; one the mirror will delete is saved.
     *
     * **A symbolic link is never read through.** A payload file whose destination is a link
     * replaces the link — {@link File::write()} renames onto it — so the record lists the path as
     * added; a surplus link is not kept at all, whatever the mirror then makes of it. Both are said
     * in a note, since a rollback will not bring either link back.
     *
     * A push that changes nothing leaves the record as it is rather than replacing it with an empty
     * one: running the same push twice should not cost the one step back the first one earned.
     *
     * @param Collection<UpdateFile> $files
     * @param UpdateManifest $manifest
     * @param Deployment $deployment
     * @param int|null $serial
     * @return UpdateReport
     *
     * @throws UpdateException if the record cannot be taken completely. Nothing live has been written.
     */
    private function record(
        Collection $files,
        UpdateManifest $manifest,
        Deployment $deployment,
        ?int $serial,
    ): UpdateReport {
        $report  = new UpdateReport();
        $entries = new Collection(RecordEntry::class);

        foreach ($files as $file) {
            if ($this->isCurrent($file, $deployment)) {
                continue;
            }

            $live = $deployment->destination($file->root, $file->name);

            if (is_link($live->path)) {
                $report = $report->noted(sprintf(
                    '%s is a symbolic link the push writes over; the record does not keep the link',
                    $file->name,
                ));
            }

            $entries = $entries->with(is_link($live->path) || !$live->exists()
                ? RecordEntry::added($file->root, $file->name, $file->contents)
                : RecordEntry::changed($file->root, $file->name, self::saveable($live, $file->name), $file->contents));
        }

        foreach (UpdateRoot::cases() as $root) {
            if (!$manifest->mirror || !self::carries($files, $root)) {
                continue;
            }

            foreach ($this->surplusIn($root, $files, $deployment) as $name) {
                $live = $deployment->destination($root, $name);

                if (is_link($live->path)) {
                    $report = $report->noted(sprintf(
                        '%s is a symbolic link the payload omits; the record does not keep it',
                        $name,
                    ));
                    continue;
                }

                $entries = $entries->with(RecordEntry::deleted($root, $name, self::saveable($live, $name)));
            }
        }

        if ($entries->isEmpty()) {
            return $report->noted('this push changes nothing, so the record of the previous release was left as it is');
        }

        $refused = new ReleaseRecord($deployment->previousRelease())->take($serial, $entries, $deployment);

        if ($refused !== null) {
            throw new UpdateException(sprintf(self::UNRECORDED, $refused));
        }

        $saved = $entries->where(static fn(RecordEntry $entry): bool => $entry->kind->saves())->count();

        return $report->noted(sprintf(
            'the release this replaces is recorded — %d saved, %d added — and `update v1 rollback` puts it back',
            $saved,
            $entries->count() - $saved,
        ));
    }

    /**
     * The bytes of $live, which the record is about to save.
     *
     * @param File $live
     * @param string $name
     * @return string
     *
     * @throws UpdateException if the file is there and cannot be read — a file the record cannot
     *                         keep is a push it cannot take back.
     */
    private static function saveable(File $live, string $name): string
    {
        return $live->read()
            ?? throw new UpdateException(sprintf(self::UNRECORDED, sprintf("'%s' could not be read", $name)));
    }

    /**
     * Renames every staged file onto its live path, in the order given, reporting each — then clears
     * the stage.
     *
     * Each rename is atomic, so no live name is ever missing or half-written; the only window left is
     * between the first rename and the last, which is a burst of renames rather than a run of
     * writes. What can still fail is what staging could not ask: a live directory this process may
     * not write into, or a disk that filled in between.
     *
     * @param Collection<UpdateFile> $files Every one already staged by {@link self::staged()}.
     * @param Deployment $deployment
     * @param UpdateReport $report What the run has to say before its first rename.
     * @return UpdateReport
     */
    private function landed(Collection $files, Deployment $deployment, UpdateReport $report): UpdateReport
    {
        $stage = $deployment->stage();

        foreach ($files as $file) {
            $live = $deployment->destination($file->root, $file->name);

            // File::moveOnto() fails on a path whose directory is missing, and fails deliberately,
            // so the caller asks. That is the arrangement Support\File states in the negative.
            if (!$live->directory()->create()) {
                $report = $report->failed($file->name, 'its directory could not be created');
                continue;
            }

            $report = $stage->file($file->name)->moveOnto($live)
                ? $report->wrote($file->name)
                : $report->failed($file->name, 'could not be renamed into place');
        }

        // Empty unless something failed to land, and then what did not land is no use to anybody:
        // the report names it, and the next push stages it again.
        $this->cleared($stage);

        return $report;
    }

    /**
     * Writes each of $files beside the roots, where nothing serves it, so that the live tree changes
     * only once every one of them is there — answering a note if a stage an earlier run left had to
     * be cleared first, or null.
     *
     * **It asks the live tree the one question staging can: whether each destination can take a
     * file at all.** A directory where the file goes, or a file where one of its directories must
     * be, would fail at the rename — after other files had landed. Asked here, either refuses the
     * whole run with nothing live changed. What it cannot ask, a live directory this process may not
     * write into, still fails at the landing, and is reported there.
     *
     * Each staged file is written at the mode of the file it replaces, so the rename keeps what
     * {@link File::write()} would have kept.
     *
     * @param Collection<UpdateFile> $files
     * @param Deployment $deployment
     * @param string $refusal The sentence a refusal opens with, taking what went wrong.
     * @return string|null
     *
     * @throws UpdateException if the stage is not a directory this class made, cannot be cleared, or
     *                         cannot take every file. The stage is cleared, and nothing live has been
     *                         written.
     */
    private function staged(Collection $files, Deployment $deployment, string $refusal): ?string
    {
        $stage = $deployment->stage();

        // Never through a link, for ReleaseRecord's reason: every write and delete below would go
        // wherever it points.
        if (is_link($stage->path) || (file_exists($stage->path) && !$stage->exists())) {
            throw new UpdateException(sprintf($refusal, $stage->path . ' is there, and is not a directory'));
        }

        $left = $stage->exists();

        if ($left && !$this->cleared($stage)) {
            throw new UpdateException(sprintf(
                $refusal,
                'what an earlier run left in ' . $stage->path . ' could not be cleared',
            ));
        }

        $failures = [];

        foreach ($files as $file) {
            $live   = $deployment->destination($file->root, $file->name);
            $staged = $stage->file($file->name);
            $why    = self::obstacle($file->root, $live, $deployment)
                ?? (self::stagedAs($staged, $file->contents, $live->permissions())
                    ? null
                    : 'it could not be written beside the roots');

            if ($why !== null) {
                $failures[] = $file->name . ' — ' . $why;
            }
        }

        if ($failures !== []) {
            $this->cleared($stage);

            throw new UpdateException(sprintf($refusal, implode('; ', $failures)));
        }

        return $left ? $stage->path . ' held what an earlier run left behind, and was cleared first' : null;
    }

    /**
     * $staged written with $contents and then given $mode — the mode of the file it will replace.
     *
     * **The mode goes on after the bytes, not before them as {@link File::write()} puts it**, and
     * the difference is deliberate. `write()` narrows first so a secret is never readable at a wider
     * mode; here every directory the stage makes is 0700, so nobody else can read the bytes at any
     * mode, and a file the live tree keeps at a mode that forbids even its owner to write — 0400 — can
     * still be staged. Narrowing first would refuse exactly that file.
     *
     * @param File $staged
     * @param string $contents
     * @param int|null $mode Null for a file with nothing to replace, which keeps the umask's mode.
     * @return bool
     */
    private static function stagedAs(File $staged, string $contents, ?int $mode): bool
    {
        return $staged->directory()->create(0o700)
            && $staged->write($contents)
            && ($mode === null || Diagnostics::muted(static fn(): bool => chmod($staged->path, $mode)));
    }

    /**
     * What in the live tree would stop $live from being replaced by a rename, or null.
     *
     * @param UpdateRoot $root
     * @param File $live
     * @param Deployment $deployment
     * @return string|null
     */
    private static function obstacle(UpdateRoot $root, File $live, Deployment $deployment): ?string
    {
        if (is_dir($live->path) && !is_link($live->path)) {
            return 'a directory is where it goes';
        }

        // The single-file root has no directories of its own to be in the way.
        $top = $deployment->directory($root)?->path ?? $live->directory()->path;

        for ($path = $live->directory()->path; strlen($path) > strlen($top); $path = dirname($path)) {
            if (file_exists($path) && !is_dir($path)) {
                return sprintf("'%s' is a file where it needs a directory", $deployment->nameOf($root, $path));
            }
        }

        return null;
    }

    /**
     * Empties the stage and removes it, answering whether it is gone.
     *
     * The walk is {@link self::walk()}, so it never follows a link; every file is unlinked and every
     * directory `rmdir()`ed, deepest first. A walk-and-delete is refused everywhere else in this
     * class, and allowed here for one reason: the stage is this class's own directory, outside every
     * root, written only by a run holding the push lock — there is nothing in it anybody else put
     * there.
     *
     * @param Directory $stage
     * @return bool
     */
    private function cleared(Directory $stage): bool
    {
        foreach ($this->walk($stage) as $path) {
            Diagnostics::muted(static fn(): bool => unlink($path));
        }

        $directories = $this->directories($stage);
        usort($directories, static fn(string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/'));

        foreach ($directories as $path) {
            Diagnostics::muted(static fn(): bool => rmdir($path));
        }

        return Diagnostics::muted(static fn(): bool => rmdir($stage->path)) || !$stage->exists();
    }

    /**
     * Restores $restore from the record, then removes $remove, then clears the record — each step
     * only if the one before it completed.
     *
     * @param Collection<UpdateFile> $restore The proved saved copies: what the mirror deleted, then
     *                                        what the push changed.
     * @param Collection<RecordEntry> $remove What the push added.
     * @param ReleaseRecord $record
     * @param Deployment $deployment
     * @param UpdateReport $report
     * @return UpdateReport
     */
    private function undo(
        Collection $restore,
        Collection $remove,
        ReleaseRecord $record,
        Deployment $deployment,
        UpdateReport $report,
    ): UpdateReport {
        $kept   = 'the record was kept, so the rollback can be run again once what failed is fixed';
        $report = $this->landed($restore, $deployment, $report);

        if (!$report->isComplete()) {
            return $report
                ->noted('nothing the push added was removed, because a restore failed')
                ->noted($kept);
        }

        foreach ($remove as $entry) {
            $report = $deployment->destination($entry->root, $entry->name)->delete()
                ? $report->removed($entry->name)
                : $report->failed($entry->name, 'could not be removed');
        }

        $this->sweepAfter($remove, $deployment);

        if (!$report->isComplete()) {
            return $report->noted($kept);
        }

        $cleared = $record->clear();

        return $cleared === null
            ? $report->noted('the record is cleared, so this rollback cannot be run twice')
            : $report->failed(basename($deployment->previousRelease()->path), 'could not be cleared: ' . $cleared);
    }

    /**
     * The digest of the regular file at $live, null where there is none, and `''` — which matches
     * no recorded state — where there is something a rollback must not reason about: a symbolic
     * link, or a file it cannot read.
     *
     * @param File $live
     * @return string|null
     */
    private static function digestOf(File $live): ?string
    {
        if (is_link($live->path)) {
            return '';
        }

        if (!$live->exists()) {
            return null;
        }

        $bytes = $live->read();

        return $bytes === null ? '' : RecordEntry::digest($bytes);
    }

    /**
     * True if the destination already holds exactly these bytes.
     *
     * **A push writes what changed, not what it carries**, which is `rsync -c`'s rule and is here
     * for a sharper reason than saving four filesystem operations. On an NFS-served host,
     * {@link File::write()} renaming its temp file onto the target means rewriting an identical
     * `public/index.php` while the request is executing out of it makes the NFS client silly-rename
     * the open inode aside as `.nfsXXXXXXXX` instead of unlinking it. The mirror then meets that
     * stray as a surplus path in the same request and cannot remove it, because the handle holding
     * it open is this very process.
     *
     * A file the payload does not change is therefore left strictly alone — not rewritten with the
     * same bytes, not touched, not chmodded. Permissions are not reconciled, deliberately: matching
     * content means a previous push wrote it, and `rsync` without `-p` makes exactly this trade.
     *
     * A file that *did* change is still rewritten under whoever holds it, so a stray can still
     * appear; what the mirror does with one is {@link self::NFS_STRAY}'s to say.
     *
     * @param UpdateFile $file
     * @param Deployment $deployment
     * @return bool
     */
    private function isCurrent(UpdateFile $file, Deployment $deployment): bool
    {
        return $deployment->destination($file->root, $file->name)->read() === $file->contents;
    }

    /**
     * Deletes what is on disk and not in the payload, then sweeps the directories that emptied —
     * under the roots the payload carries, and no others.
     *
     * @param Collection<UpdateFile> $files
     * @param UpdateReport $report
     * @param Deployment $deployment
     * @return UpdateReport
     */
    private function mirror(Collection $files, UpdateReport $report, Deployment $deployment): UpdateReport
    {
        // The root loop is here rather than inside surplus() for the reason UpdateFile exists on
        // the writing side: these names are *built* from a root a line earlier, so asking which
        // root they are under is a question with an answer already in hand — and asking it anyway
        // produced a null branch that could not happen and would have skipped a delete in silence.
        foreach (UpdateRoot::cases() as $root) {
            if (!self::carries($files, $root)) {
                $report = self::untouched($report, $root, $deployment);
                continue;
            }

            foreach ($this->surplusIn($root, $files, $deployment) as $name) {
                $report = $deployment->destination($root, $name)->delete()
                    ? $report->removed($name)
                    : $report->failed($name, 'could not be removed');
            }

            foreach ($this->straysIn($root, $deployment) as $stray) {
                $name   = $deployment->nameOf($root, $stray->path);
                $report = $report->noted($stray->delete()
                    ? sprintf('%s, a file the NFS client had renamed aside, was let go and is removed', $name)
                    : sprintf(
                        '%s is a file the NFS client renamed aside, still held open by a running worker; '
                        . 'it goes when the worker lets go, or over the mount, and is never served meanwhile',
                        $name,
                    ));
            }
        }

        $this->sweep($files, $deployment);

        return $report;
    }

    /**
     * The files under $root the NFS client renamed aside — see {@link self::NFS_STRAY}.
     *
     * @param UpdateRoot $root
     * @param Deployment $deployment
     * @return Collection<File>
     */
    private function straysIn(UpdateRoot $root, Deployment $deployment): Collection
    {
        $directory = $deployment->directory($root);
        $strays    = new Collection(File::class);

        // The single-file root has no directory to hold a stray, and a tree not deployed yet has none.
        foreach ($directory?->exists() === true ? $this->walk($directory) : [] as $path) {
            if (preg_match(self::NFS_STRAY, basename($path)) === 1) {
                $strays = $strays->with(new File($path));
            }
        }

        return $strays;
    }

    /**
     * Whether the payload holds at least one file under $root.
     *
     * **This is the question the mirror asks before it deletes anything under a root**, and the
     * answer "no" means the push says nothing about that root — not that the root should be empty.
     * The two readings differ by exactly one deployed framework: a push from a clone whose submodule
     * was never checked out carries no `phpanta/`, and a mirror that took the silence as an
     * instruction would delete the framework every request runs on, the admin included, leaving only a
     * full deploy to put it back.
     *
     * @param Collection<UpdateFile> $files
     * @param UpdateRoot $root
     * @return bool
     */
    private static function carries(Collection $files, UpdateRoot $root): bool
    {
        return $files->first(static fn(UpdateFile $file): bool => $file->root === $root) !== null;
    }

    /**
     * $report, noting that $root was left as it is — where there is anything there to leave.
     *
     * @param UpdateReport $report
     * @param UpdateRoot $root
     * @param Deployment $deployment
     * @return UpdateReport
     */
    private static function untouched(UpdateReport $report, UpdateRoot $root, Deployment $deployment): UpdateReport
    {
        return $deployment->directory($root)?->exists() === true
            ? $report->noted(sprintf('%s/ is not in this push, so the mirror left it as it is', $root->value))
            : $report;
    }

    /**
     * The names under $root that exist on disk and are not in the payload.
     *
     * @param UpdateRoot $root
     * @param Collection<UpdateFile> $files
     * @param Deployment $deployment
     * @return list<string>
     */
    #[BareArray(
        'the two sides of a diff — a lookup keyed by name and the list that falls out of it — both '
        . 'built by a loop and read by one, never crossing a boundary. A collection here would be '
        . 'a copy per file for a shape that never leaves these two methods.',
    )]
    private function surplusIn(UpdateRoot $root, Collection $files, Deployment $deployment): array
    {
        $directory = $deployment->directory($root);

        // A single-file root has nothing that can go stale: it is replaced or it is left alone.
        if ($directory === null || !$directory->exists()) {
            return [];
        }

        $packed = [];
        foreach ($files as $file) {
            $packed[$file->name] = true;
        }

        $surplus = [];
        foreach ($this->walk($directory) as $path) {
            $name = $deployment->nameOf($root, $path);

            // The pattern is asked again on the way out, not because the payload could have put
            // this name here — it could not, these are files already on disk — but because a name
            // this class would refuse to *write* is one it must refuse to *delete*. That symmetry
            // is what keeps the mirror from being a second, weaker path to unlink().
            //
            // A stray the NFS client left is not surplus either: it is not the site's to record or
            // count, and mirror() deals with it apart — see NFS_STRAY.
            if (
                !isset($packed[$name])
                && preg_match(self::SAFE_NAME, $name) === 1
                && preg_match(self::NFS_STRAY, basename($path)) !== 1
            ) {
                $surplus[] = $name;
            }
        }

        return $surplus;
    }

    /**
     * Every regular file under $directory, recursively, as absolute paths.
     *
     * Local to this class rather than added to {@link Directory}, whose non-recursion is a stated
     * decision and stays one. Listing is not deleting: what comes back is a list of paths, and every
     * one is checked against {@link self::SAFE_NAME} and matched to a root before anything happens
     * to it.
     *
     * Dotfiles are included deliberately — `public/.htaccess` is one, and a mirror that could not
     * see it would report the file as surplus on every push while never being able to replace it.
     *
     * @param Directory $directory
     * @return list<string>
     */
    #[BareArray(
        'a recursive listing accumulated in a loop, where with() would copy the whole list once '
        . 'per file. Read by surplus() and sweep(), both one method away.',
    )]
    private function walk(Directory $directory): array
    {
        $paths = [];

        foreach ($this->entries($directory) as $path) {
            // A symlink is never descended into, even one pointing at a directory. A push cannot
            // write one — TarArchive refuses the member type — so any that is here was placed by
            // something outside this endpoint, and following it would let the mirror read, and then
            // delete *through*, a tree outside the roots. Treated as a leaf, the link itself is what
            // is weighed against the payload, and unlink() removes the link rather than its target.
            if (is_dir($path) && !is_link($path)) {
                $paths = array_merge($paths, $this->walk(new Directory($path)));
                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Removes directories the mirror emptied, under the roots the payload carries.
     *
     * **Deliberately `rmdir()` rather than {@link Directory::remove()}, and the difference is the
     * whole point.** That method takes away the files a directory holds *and then* the directory —
     * which is correct for tearing down a fixture and catastrophic here, where a directory the
     * payload simply did not mention would have its contents deleted on the way past. `rmdir()`
     * refuses a directory that is not empty, and that refusal is exactly the condition being asked
     * about, so the check and the action are the same call and cannot disagree.
     *
     * Deepest first, so a directory whose only contents were themselves emptied directories goes
     * too.
     *
     * @param Collection<UpdateFile> $files
     * @param Deployment $deployment
     * @return void
     */
    private function sweep(Collection $files, Deployment $deployment): void
    {
        foreach (UpdateRoot::cases() as $root) {
            $directory = $deployment->directory($root);
            if ($directory === null || !$directory->exists() || !self::carries($files, $root)) {
                continue;
            }

            $directories = $this->directories($directory);
            usort(
                $directories,
                static fn(string $a, string $b): int => substr_count($b, '/') <=> substr_count($a, '/'),
            );

            foreach ($directories as $path) {
                Diagnostics::muted(static fn(): bool => rmdir($path));
            }
        }
    }

    /**
     * Removes the directories a rollback emptied by removing what the push added — and only those:
     * each removed file's own directories, up to and never including its root's.
     *
     * Narrower than {@link self::sweep()}, deliberately. That one sweeps every empty directory under
     * a root, which is right after a mirror and wrong here, where a directory that was empty before
     * the push is part of the release being put back. Deepest first, and `rmdir()` for sweep()'s
     * reason: it refuses a directory that is not empty.
     *
     * @param Collection<RecordEntry> $removed
     * @param Deployment $deployment
     * @return void
     */
    private function sweepAfter(Collection $removed, Deployment $deployment): void
    {
        $directories = [];

        foreach ($removed as $entry) {
            $root = $deployment->directory($entry->root);

            if ($root === null) {
                continue;
            }

            $directory = dirname($deployment->destination($entry->root, $entry->name)->path);

            while (strlen($directory) > strlen($root->path)) {
                $directories[$directory] = substr_count($directory, '/');
                $directory               = dirname($directory);
            }
        }

        arsort($directories);

        foreach ($directories as $directory => $depth) {
            Diagnostics::muted(static fn(): bool => rmdir($directory));
        }
    }

    /**
     * Every directory under $directory, recursively, as absolute paths.
     *
     * @param Directory $directory
     * @return list<string>
     */
    #[BareArray('an accumulator, read once by sweep() and never crossing a boundary.')]
    private function directories(Directory $directory): array
    {
        $paths = [];

        foreach ($this->entries($directory) as $path) {
            // Not through a symlink, for the reason walk() gives: sweep() would otherwise rmdir its
            // way into a directory outside the roots.
            if (is_dir($path) && !is_link($path)) {
                $paths[] = $path;
                $paths   = array_merge($paths, $this->directories(new Directory($path)));
            }
        }

        return $paths;
    }

    /**
     * One directory's entries, dotfiles included and `.`/`..` excluded.
     *
     * `scandir()` rather than `glob()`: a glob reads its argument as a pattern, so a deployment whose
     * path holds a `[` or a `*` would list nothing — and a mirror that sees nothing on disk deletes
     * nothing, where the writer's matching walk would pack nothing and the mirror would then delete
     * everything. It also needs no `GLOB_BRACE`, which some C libraries do not have.
     *
     * @param Directory $directory
     * @return list<string>
     */
    #[BareArray("scandir()'s own shape. This is the door the two recursive walks above share.")]
    private function entries(Directory $directory): array
    {
        $entries = [];

        foreach (Diagnostics::muted(static fn(): array|false => scandir($directory->path)) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entries[] = $directory->path . '/' . $entry;
        }

        return $entries;
    }
}
