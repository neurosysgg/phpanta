<?php

declare(strict_types=1);

namespace Phpanta\Service;

use NoDiscard;
use Phpanta\Exception\UpdateException;
use Phpanta\Model\Update\Deployment;
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
 * it is why there is no staging directory: staging exists to make a half-run recoverable, and a run
 * that cannot start half-way needs no recovery. It would also have cost something real, since
 * {@link Directory::temporary()} lives under `sys_get_temp_dir()` and a `rename()` across
 * filesystems fails outright.
 *
 * **Each file lands through {@link File::write()}**, which already writes beside the target and
 * renames over it. Every file therefore appears atomically and always within one filesystem, and
 * the only window left is between files — which a site's caching design has to tolerate: assets
 * content-addressed, documents `no-cache`.
 *
 * **The mirror is an enumerated delete, never a recursive one.** What is on disk is walked, diffed
 * against the payload, and each surplus path is checked by the same rules an added path passes
 * before {@link File::delete()} is called on it — one named file at a time. It walks **only the roots
 * the payload carries**: a push with nothing under `phpanta/` says nothing about `phpanta/`, and
 * reading that silence as "delete all of it" is how a push from a clone without the framework
 * checked out would take the framework off the server. And it runs **only after every write
 * succeeded**, because deleting the old half of a change whose new half did not land leaves neither.
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

    /** ustar's own limit, and a bound on how deep any of this can go. */
    private const int MAX_NAME = 255;

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
     * @return UpdateReport
     *
     * @throws UpdateException if the archive cannot be expanded, or holds a member this class will
     *                         not write. Nothing has been written when this throws.
     */
    #[NoDiscard('the report is the endpoint\'s entire response; dropping it sends an empty 200')]
    public function apply(string $archive, UpdateManifest $manifest): UpdateReport
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

        $report = $this->write($files, $deployment);

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
     * in place of one — see the two refusals below that ask `isDirectory`. Both are shapes no `tar`
     * produces and neither could be reached without the private key; they are refused here because
     * the alternative is a destination computed from them, and the one thing this class promises is
     * that a member either passes every rule or writes nothing.
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

        if (preg_match(self::SAFE_NAME, $name) !== 1) {
            throw new UpdateException(sprintf(
                "the archive holds '%s', which is not a plain relative path this site will write",
                $name,
            ));
        }

        // `.` and `..` are spelled with characters SAFE_NAME allows, so they pass the pattern and
        // are refused here instead. This is the check that would matter if the pattern were ever
        // widened, which is exactly why it is separate from it rather than folded in.
        foreach (explode('/', $name) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new UpdateException(sprintf("the archive holds '%s', which walks the tree", $name));
            }
        }

        $root = UpdateRoot::of($name);

        if ($root === null) {
            throw new UpdateException(sprintf(
                "the archive holds '%s', which is under none of the roots a push may write (%s)",
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
        if (!$entry->isDirectory && $root->isTree() && $name === $root->value) {
            throw new UpdateException(sprintf(
                "the archive holds '%s' as a regular file, but that name is a tree this push writes "
                . 'into rather than a file it writes',
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
     * Writes every file, reporting each.
     *
     * @param Collection<UpdateFile> $files
     * @param Deployment $deployment
     * @return UpdateReport
     */
    private function write(Collection $files, Deployment $deployment): UpdateReport
    {
        $report = new UpdateReport();

        foreach ($files as $file) {
            if ($this->isCurrent($file, $deployment)) {
                $report = $report->kept($file->name);
                continue;
            }

            $destination = $deployment->destination($file->root, $file->name);

            // File::write() fails on a path whose directory is missing, and fails deliberately, so
            // the caller asks. That is the arrangement Support\File states in the negative.
            if (!$destination->directory()->create()) {
                $report = $report->failed($file->name, 'its directory could not be created');
                continue;
            }

            $report = $destination->write($file->contents)
                ? $report->wrote($file->name)
                : $report->failed($file->name, 'could not be written');
        }

        return $report;
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
        }

        $this->sweep($files, $deployment);

        return $report;
    }

    /**
     * Whether the payload holds at least one file under $root.
     *
     * **This is the question the mirror asks before it deletes anything under a root**, and the
     * answer "no" means the push says nothing about that root — not that the root should be empty.
     * The two readings differ by exactly one deployed framework: a push from a clone whose submodule
     * was never checked out carries no `phpanta/`, and a mirror that took the silence as an
     * instruction would delete the framework every request runs on, `/api` included, leaving only a
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
            if (!isset($packed[$name]) && preg_match(self::SAFE_NAME, $name) === 1) {
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
