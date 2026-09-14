<?php

declare(strict_types=1);

namespace Phpanta\Service;

use Closure;
use NoDiscard;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Update\Deployment;
use Phpanta\Model\Update\ProbeReport;
use Phpanta\Model\Update\UpdateRoot;
use Phpanta\Support\BareArray;
use Phpanta\Support\Collection;
use Phpanta\Support\Diagnostics;

/**
 * The FilesystemProbe class. What the filesystem a deployment sits on lets a push do, measured by
 * doing it in a scratch directory and taking every trace of it away again.
 *
 * **It exists because a push that stages its tree before swapping it in rests on facts no document
 * holds.** Whether a directory can be renamed while a worker holds a file inside it open, whether
 * a rename onto an existing directory is allowed, how long two renames leave a name with nothing
 * at it, whether the temporary directory is even on the same device — each is a property of one
 * host's filesystem, and on a shared host behind NFS none of them can be read off a manual. They
 * are asked here, on the host, the way the extensions are asked in `health`: by being used.
 *
 * **Where it works is the deployment's own filesystem**: a directory beside the roots, under the
 * name {@link Deployment::probeDirectory()} gives it, so the answers are about the tree a push
 * would move and the mirror, which walks only the roots, never meets it.
 *
 * **Every step answers and none throws.** A failure is the step's answer, in PHP's own words, so a
 * probe that meets a host which refuses half of it still reports the other half. And every step
 * takes away what it made before the next one begins — the enumerated delete the mirror uses,
 * never a recursive one — so the one thing that can be left behind is what the host itself kept,
 * which the last line reports.
 */
final readonly class FilesystemProbe
{
    /** The line that says where the probe worked. */
    private const string SCRATCH = 'scratch';

    /** What a file made by a step holds. Any bytes would do; these say where they came from. */
    private const string CONTENTS = 'probe';

    /** The name every step gives the one file its directories hold. */
    private const string FILE = 'inside';

    /** How many nanoseconds `hrtime()` counts to the microsecond each timing is reported in. */
    private const int NANOSECONDS = 1000;

    /** A mebibyte, the unit free space is reported in. */
    private const int MEBIBYTE = 1_048_576;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Deployment|null $deployment Where the scratch directory goes. {@link UpdateApplier}'s
     *                                    seam, for its reason: a test hands a sandbox and cannot
     *                                    reach the live tree.
     */
    public function __construct(private ?Deployment $deployment = null) {}

    /**
     * Runs every step, or names the directory it would have run them in.
     *
     * @param bool $apply False for a dry run, which writes nothing.
     * @return ProbeReport
     */
    #[NoDiscard('the report is the endpoint\'s entire response; dropping it sends an empty 200')]
    public function run(bool $apply): ProbeReport
    {
        $deployment = $this->deployment ?? Deployment::current();
        $scratch    = $deployment->probeDirectory()->path;
        $facts      = new Collection(HealthFact::class)->with(new HealthFact(self::SCRATCH, $scratch));

        if (!$apply) {
            return new ProbeReport($facts, false, true);
        }

        $refused = self::attempt(static fn(): bool => mkdir($scratch, 0o700));

        if ($refused !== null) {
            return new ProbeReport(
                new Collection(HealthFact::class)
                    ->with(new HealthFact(self::SCRATCH, $scratch . ' could not be created — ' . $refused)),
                true,
                false,
            );
        }

        // Each argument is evaluated before with() is called, in the order written, so the steps
        // run one after the other in exactly the order the report lists them.
        $facts = $facts
            ->with(new HealthFact('devices', self::devices(dirname($scratch), $deployment)))
            ->with(new HealthFact('free space', self::space(dirname($scratch))))
            ->with(new HealthFact('file rename', self::fileRename($scratch)))
            ->with(new HealthFact('directory rename', self::directoryRename($scratch)))
            ->with(new HealthFact('with a file open', self::heldInside($scratch)))
            ->with(new HealthFact('over an open file', self::heldOver($scratch)))
            ->with(new HealthFact('onto an empty dir', self::ontoEmpty($scratch)))
            ->with(new HealthFact('onto a full dir', self::ontoFull($scratch)))
            ->with(new HealthFact('swap window', self::swapWindow($scratch)))
            ->with(new HealthFact('hard link', self::hardLink($scratch)))
            ->with(new HealthFact('symbolic link', self::symbolicLink($scratch)));

        $left = self::leftBehind($scratch);

        return new ProbeReport($facts->with(new HealthFact('left behind', $left ?? 'nothing')), true, $left === null);
    }

    /**
     * Whether the webroot and the temporary directory are on the device the deployment is.
     *
     * The question the old argument against a staging directory never measured: `Directory::temporary()`
     * lives under `sys_get_temp_dir()`, and a directory `rename()` from another device fails outright
     * where a file's is quietly a copy.
     *
     * @param string $above
     * @param Deployment $deployment
     * @return string
     */
    private static function devices(string $above, Deployment $deployment): string
    {
        $device = self::device($above);

        return sprintf(
            'webroot on %s; %s on %s',
            self::compared($device, self::device($deployment->directory(UpdateRoot::Public)?->path ?? $above)),
            sys_get_temp_dir(),
            self::compared($device, self::device(sys_get_temp_dir())),
        );
    }

    /**
     * The device $path is on, or null where it cannot be asked.
     *
     * @param string $path
     * @return int|null
     */
    private static function device(string $path): ?int
    {
        $stat = Diagnostics::muted(
            #[BareArray('stat() answers in an array, or false: this is the door it comes through')]
            static fn(): array|false => stat($path),
        );

        return $stat === false ? null : $stat['dev'];
    }

    /**
     * @param int|null $ours
     * @param int|null $theirs
     * @return string
     */
    private static function compared(?int $ours, ?int $theirs): string
    {
        $same = $ours === $theirs ? 'the same device' : 'another device';

        return $ours === null || $theirs === null ? 'no answer' : $same;
    }

    /**
     * How much room there is for a second tree beside the first.
     *
     * What the filesystem says, which on a shared host may be the export's and not the account's
     * quota — a lower bound on nothing, but an upper bound worth having.
     *
     * @param string $above
     * @return string
     */
    private static function space(string $above): string
    {
        return sprintf(
            '%s free of %s',
            self::mebibytes(Diagnostics::muted(static fn(): float|false => disk_free_space($above))),
            self::mebibytes(Diagnostics::muted(static fn(): float|false => disk_total_space($above))),
        );
    }

    /**
     * @param float|false $bytes What `disk_free_space()` or `disk_total_space()` answered.
     * @return string
     */
    private static function mebibytes(float|false $bytes): string
    {
        return $bytes === false ? 'what it will not say' : sprintf('%d MiB', (int) ($bytes / self::MEBIBYTE));
    }

    /**
     * A file renamed within one directory: what every push already does, as the baseline the rest
     * are read against.
     *
     * @param string $scratch
     * @return string
     */
    private static function fileRename(string $scratch): string
    {
        $failed = self::written($scratch . '/file-a')
            ?? self::attempt(static fn(): bool => rename($scratch . '/file-a', $scratch . '/file-b'));

        self::remove($scratch, 'file-a', 'file-b');

        return self::answered($failed, 'yes');
    }

    /**
     * A directory holding a file, renamed, and how long the rename took.
     *
     * @param string $scratch
     * @return string
     */
    private static function directoryRename(string $scratch): string
    {
        $failed = self::made($scratch, 'moving-a');
        $start  = hrtime(true);
        $failed ??= self::attempt(static fn(): bool => rename($scratch . '/moving-a', $scratch . '/moving-b'));
        $took   = hrtime(true) - $start;

        self::remove($scratch, 'moving-a/' . self::FILE, 'moving-b/' . self::FILE, 'moving-a', 'moving-b');

        return self::answered($failed, sprintf('yes, in %d µs', intdiv($took, self::NANOSECONDS)));
    }

    /**
     * A directory renamed while this process holds a file inside it open — a worker still running
     * the old `index.php` while its tree is swapped out from under it.
     *
     * The handle is read after the rename, and the directory is searched for the name the NFS client
     * gives a file it has renamed aside, because either would be the cost of a swap.
     *
     * @param string $scratch
     * @return string
     */
    private static function heldInside(string $scratch): string
    {
        $failed = self::made($scratch, 'held-a');
        $handle = Diagnostics::muted(static fn(): mixed => fopen($scratch . '/held-a/' . self::FILE, 'r'));
        $failed ??= self::attempt(static fn(): bool => rename($scratch . '/held-a', $scratch . '/held-b'));
        $reads  = is_resource($handle) && fread($handle, 64) === self::CONTENTS;
        $strays = self::strays($scratch . '/held-b');

        if (is_resource($handle)) {
            fclose($handle);
        }

        self::remove($scratch, 'held-a/' . self::FILE, 'held-b/' . self::FILE, 'held-a', 'held-b');

        return self::answered($failed, sprintf(
            'yes; the open file %s, and %d .nfs strays appeared',
            $reads ? 'still reads' : 'no longer reads',
            $strays,
        ));
    }

    /**
     * A file renamed over one this process holds open — what a push does to `index.php` today —
     * with the directory searched for a stray while the handle is open and again once it is closed.
     *
     * Where the filesystem is not an NFS client's, both counts are nought: the old inode simply
     * lives on unnamed until the handle closes. Where it is, the first count is the silly-rename,
     * and the second says whether closing the last handle is what releases it.
     *
     * @param string $scratch
     * @return string
     */
    private static function heldOver(string $scratch): string
    {
        $failed = self::made($scratch, 'over');
        $handle = Diagnostics::muted(static fn(): mixed => fopen($scratch . '/over/' . self::FILE, 'r'));
        $failed ??= self::written($scratch . '/over/new')
            ?? self::attempt(static fn(): bool => rename($scratch . '/over/new', $scratch . '/over/' . self::FILE));
        $held   = self::strays($scratch . '/over');
        $reads  = is_resource($handle) && fread($handle, 64) === self::CONTENTS;

        if (is_resource($handle)) {
            fclose($handle);
        }

        clearstatcache();
        $after = self::strays($scratch . '/over');

        self::remove($scratch, 'over/new', 'over/' . self::FILE, 'over');

        return self::answered($failed, sprintf(
            'yes; the open file %s the old bytes; %d .nfs strays while it was open, %d after it closed',
            $reads ? 'still reads' : 'no longer reads',
            $held,
            $after,
        ));
    }

    /**
     * A directory renamed onto an empty one, which POSIX allows — the one way a swap could replace a
     * name without a moment where nothing is at it, if the old tree were emptied first.
     *
     * @param string $scratch
     * @return string
     */
    private static function ontoEmpty(string $scratch): string
    {
        $failed = self::made($scratch, 'onto-a')
            ?? self::attempt(static fn(): bool => mkdir($scratch . '/onto-b'))
            ?? self::attempt(static fn(): bool => rename($scratch . '/onto-a', $scratch . '/onto-b'));

        self::remove($scratch, 'onto-a/' . self::FILE, 'onto-b/' . self::FILE, 'onto-a', 'onto-b');

        return self::answered($failed, 'yes');
    }

    /**
     * A directory renamed onto one that holds a file, which POSIX refuses — asked anyway, because a
     * filesystem that allowed it would make a swap one call.
     *
     * @param string $scratch
     * @return string
     */
    private static function ontoFull(string $scratch): string
    {
        $failed = self::made($scratch, 'full-a')
            ?? self::made($scratch, 'full-b')
            ?? self::attempt(static fn(): bool => rename($scratch . '/full-a', $scratch . '/full-b'));

        self::remove($scratch, 'full-a/' . self::FILE, 'full-b/' . self::FILE, 'full-a', 'full-b');

        return self::answered($failed, 'yes — the directory it landed on was replaced');
    }

    /**
     * Two directory renames, the swap a staged push would make: the live tree aside, then the staged
     * one into its place — and how long nothing was at the name in between.
     *
     * The gap is measured from the first rename's return to the second's, so it includes this
     * class's own few microseconds around each call; it is a ceiling, not a floor.
     *
     * @param string $scratch
     * @return string
     */
    private static function swapWindow(string $scratch): string
    {
        $failed = self::made($scratch, 'live') ?? self::made($scratch, 'stage');
        $start  = hrtime(true);
        $failed ??= self::attempt(static fn(): bool => rename($scratch . '/live', $scratch . '/retired'));
        $gone   = hrtime(true);
        $failed ??= self::attempt(static fn(): bool => rename($scratch . '/stage', $scratch . '/live'));
        $back   = hrtime(true);

        self::remove(
            $scratch,
            'live/' . self::FILE,
            'retired/' . self::FILE,
            'stage/' . self::FILE,
            'live',
            'retired',
            'stage',
        );

        return self::answered($failed, sprintf(
            '%d µs with nothing at the name, %d µs for both renames',
            intdiv($back - $gone, self::NANOSECONDS),
            intdiv($back - $start, self::NANOSECONDS),
        ));
    }

    /**
     * A second name for one file, which would let a stage share an unchanged file's bytes with the
     * live tree rather than copying them.
     *
     * @param string $scratch
     * @return string
     */
    private static function hardLink(string $scratch): string
    {
        $failed = self::written($scratch . '/link-a')
            ?? self::attempt(static fn(): bool => link($scratch . '/link-a', $scratch . '/link-b'));

        self::remove($scratch, 'link-b', 'link-a');

        return self::answered($failed, 'yes');
    }

    /**
     * Whether a symbolic link can be made at all. Informational: a swap by link is ruled out on
     * other grounds — `__DIR__` resolves one, so the deployment's own root would move with it.
     *
     * @param string $scratch
     * @return string
     */
    private static function symbolicLink(string $scratch): string
    {
        $failed = self::attempt(static fn(): bool => symlink('nowhere', $scratch . '/pointer'));

        self::remove($scratch, 'pointer');

        return self::answered($failed, 'yes');
    }

    /**
     * The scratch directory taken away, or why it could not be.
     *
     * Every step removed what it made, so a directory that will not go is holding something the
     * host kept — and the only honest report of that is its path, for somebody to look at over the
     * mount.
     *
     * @param string $scratch
     * @return string|null Null when there is nothing left.
     */
    private static function leftBehind(string $scratch): ?string
    {
        $gone = self::attempt(static fn(): bool => rmdir($scratch)) === null;

        return $gone ? null : $scratch . ' is still there, holding something the host kept';
    }

    /**
     * A directory under $scratch with one file in it, or why it could not be made.
     *
     * @param string $scratch
     * @param string $name
     * @return string|null
     */
    private static function made(string $scratch, string $name): ?string
    {
        return self::attempt(static fn(): bool => mkdir($scratch . '/' . $name))
            ?? self::written($scratch . '/' . $name . '/' . self::FILE);
    }

    /**
     * $path written with the probe's bytes, or why it could not be.
     *
     * @param string $path
     * @return string|null
     */
    private static function written(string $path): ?string
    {
        return self::attempt(static fn(): bool => file_put_contents($path, self::CONTENTS) !== false);
    }

    /**
     * How many files in $directory carry the name the NFS client gives a file it renamed aside.
     *
     * @param string $directory
     * @return int
     */
    private static function strays(string $directory): int
    {
        $names = Diagnostics::muted(
            #[BareArray('scandir() answers in an array, or false: this is the door it comes through')]
            static fn(): array|false => scandir($directory),
        );
        $count = 0;

        foreach ($names === false ? [] : $names as $name) {
            $count += (int) preg_match(UpdateApplier::NFS_STRAY, $name);
        }

        return $count;
    }

    /**
     * Removes each of $names under $scratch that is there — links and files unlinked, directories
     * `rmdir()`ed — in the order given, so a directory's file goes before the directory.
     *
     * Named, never walked: every step passes exactly what it may have made, so this can remove
     * nothing it did not create. A name that is not there is one the step's own failure never made.
     *
     * @param string $scratch
     * @param string ...$names
     * @return void
     */
    private static function remove(string $scratch, string ...$names): void
    {
        foreach ($names as $name) {
            $path = $scratch . '/' . $name;

            if (is_link($path) || is_file($path)) {
                Diagnostics::muted(static fn(): bool => unlink($path));
            } elseif (is_dir($path)) {
                Diagnostics::muted(static fn(): bool => rmdir($path));
            }
        }
    }

    /**
     * Runs $operation, and answers null if it succeeded or what PHP said if it did not.
     *
     * @param Closure $operation Answering true on success.
     * @return string|null
     */
    private static function attempt(Closure $operation): ?string
    {
        $run = Diagnostics::watched($operation);

        return $run->result === true ? null : ($run->reported->last() ?? 'it failed without a word');
    }

    /**
     * The step's line: what it found, or `no` and why.
     *
     * @param string|null $failed
     * @param string $found
     * @return string
     */
    private static function answered(?string $failed, string $found): string
    {
        return $failed === null ? $found : 'no — ' . $failed;
    }
}
