<?php

declare(strict_types=1);

namespace Phpanta\Model\Update;

use NoDiscard;
use Phpanta\Support\BareString;
use Phpanta\Support\Collection;

/**
 * The UpdateReport class. What a push did, as the endpoint's whole response body.
 *
 * **It is the only account of the run there will be.** On a host with `display_errors` off and
 * `error_log` the empty string — an ordinary shared-host setup — a PHP warning goes nowhere at
 * all. Anything this class does not say is not written down anywhere, which is why it reports
 * per-path rather than in counts and why a file that could not be written is named rather than
 * summed. The one exception is {@link self::kept()}, and it proves the rule: an unchanged file is
 * the absence of an action, and naming every one of them would bury the few that changed.
 *
 * Immutable and copy-returning like everything else here, so the applier threads one report through
 * its steps rather than mutating a running tally — see {@link Collection::with()}, whose naming
 * argument this follows for the same reason.
 */
#[BareString(
    'string',
    "a scalar type name standing in a class-string's place, spelled the way get_debug_type() "
    . 'spells it. Support\\TypedItems carries the same excuse for the same word, which is what '
    . 'makes this one necessary rather than merely tidy.',
)]
final readonly class UpdateReport
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<string> $written
     * @param Collection<string> $unchanged Paths the payload named whose bytes were already there.
     *                                      Counted rather than listed: nothing happened to them,
     *                                      and listing them would bury the handful that changed.
     * @param Collection<string> $deleted
     * @param Collection<string> $failed Paths that could not be written or removed. A non-empty one
     *                                   is what turns the response into a 500.
     * @param bool $applied False for a dry run, so a reader cannot mistake "nothing to do" for
     *                      "nothing was done".
     * @param Collection<string> $notes What the run decided not to do, and why — a root the push did
     *                                  not carry, a mirror that did not run. Not a path each, so not
     *                                  a count; a sentence each.
     */
    public function __construct(
        private Collection $written = new Collection('string'),
        private Collection $unchanged = new Collection('string'),
        private Collection $deleted = new Collection('string'),
        private Collection $failed = new Collection('string'),
        private bool       $applied = true,
        private Collection $notes = new Collection('string'),
    ) {}

    /**
     * A copy noting that $path was written.
     *
     * @param string $path
     * @return self
     */
    #[NoDiscard('the report copies rather than accumulating; a dropped call records nothing')]
    public function wrote(string $path): self
    {
        return new self(
            $this->written->with($path),
            $this->unchanged,
            $this->deleted,
            $this->failed,
            $this->applied,
            $this->notes,
        );
    }

    /**
     * A copy noting that $path was already current, so nothing was written.
     *
     * **This is the member that keeps a push from disturbing a file it is not changing**, and on an
     * NFS-served host that is not an optimisation. {@link \Phpanta\Support\File::write()} renames its
     * temp file *onto* the target — so rewriting `public/index.php`, the script the request is
     * running out of, makes the NFS client silly-rename the open inode aside as `.nfsXXXXXXXX`
     * rather than unlinking it. That stray then reads as surplus to the mirror in the same request
     * and cannot be deleted, because the handle keeping it alive is ours. One push, one undeletable
     * file in the webroot, one spurious failure. Measured, not reasoned about.
     *
     * @param string $path
     * @return self
     */
    #[NoDiscard('the report copies rather than accumulating; a dropped call records nothing')]
    public function kept(string $path): self
    {
        return new self(
            $this->written,
            $this->unchanged->with($path),
            $this->deleted,
            $this->failed,
            $this->applied,
            $this->notes,
        );
    }

    /**
     * A copy noting that $path was deleted.
     *
     * @param string $path
     * @return self
     */
    #[NoDiscard('the report copies rather than accumulating; a dropped call records nothing')]
    public function removed(string $path): self
    {
        return new self(
            $this->written,
            $this->unchanged,
            $this->deleted->with($path),
            $this->failed,
            $this->applied,
            $this->notes,
        );
    }

    /**
     * A copy noting that $path could not be written or removed.
     *
     * @param string $path
     * @param string $why
     * @return self
     */
    #[NoDiscard('the report copies rather than accumulating; a dropped failure is a silent one')]
    public function failed(string $path, string $why): self
    {
        return new self(
            $this->written,
            $this->unchanged,
            $this->deleted,
            $this->failed->with($path . ' — ' . $why),
            $this->applied,
            $this->notes,
        );
    }

    /**
     * A copy noting something the run decided not to do, in a sentence.
     *
     * @param string $note
     * @return self
     */
    #[NoDiscard('the report copies rather than accumulating; a dropped note is a decision nobody hears of')]
    public function noted(string $note): self
    {
        return new self(
            $this->written,
            $this->unchanged,
            $this->deleted,
            $this->failed,
            $this->applied,
            $this->notes->with($note),
        );
    }

    /**
     * A copy marked as a dry run.
     *
     * @return self
     */
    #[NoDiscard('the report copies rather than accumulating; a dropped call still reads as applied')]
    public function dryRun(): self
    {
        return new self($this->written, $this->unchanged, $this->deleted, $this->failed, false, $this->notes);
    }

    /**
     * True if every step of the run succeeded.
     *
     * @return bool
     */
    #[NoDiscard('this decides the response status; dropping it reports a failed push as a 200')]
    public function isComplete(): bool
    {
        return $this->failed->isEmpty();
    }

    /**
     * The response body.
     *
     * @return string
     */
    #[NoDiscard('the rendered report; dropping it sends an empty response')]
    public function render(): string
    {
        $lines = new Collection('string')
            ->with($this->applied ? 'applied' : 'dry run — nothing was written')
            ->with(sprintf(
                'written %d  unchanged %d  deleted %d  failed %d',
                $this->written->count(),
                $this->unchanged->count(),
                $this->deleted->count(),
                $this->failed->count(),
            ));

        foreach ($this->notes as $note) {
            $lines = $lines->with('note: ' . $note);
        }

        foreach ([['+', $this->written], ['-', $this->deleted], ['!', $this->failed]] as [$mark, $paths]) {
            foreach ($paths as $path) {
                $lines = $lines->with($mark . ' ' . $path);
            }
        }

        return $lines->join("\n") . "\n";
    }
}
