<?php

declare(strict_types=1);

namespace Phpanta\Model\Update;

use Phpanta\Service\UpdateApplier;

/**
 * The UpdateFile class. One member of a verified payload, with the root that claimed it already
 * resolved.
 *
 * **It exists so that the root is decided once**, by the check that is allowed to refuse, rather
 * than re-derived by every step that needs it. {@link UpdateRoot::of()} answers `?UpdateRoot`,
 * which is the honest signature for a question asked about an arbitrary string — but by the time
 * the applier is writing, the string is not arbitrary any more: every name has been through
 * {@link UpdateApplier::check()}, which throws for a name under no root. Asking again produced a
 * null that could not happen, three times over, each answered with a `continue` that no test could
 * reach and no reader could evaluate.
 *
 * Dead defensive code is worse than none here. A `continue` on an impossible null reads as "this
 * member is quietly skipped", which is precisely the behaviour a mirroring updater must never
 * have — and nothing in the report would have said so. Carrying the resolved root removes the
 * branch rather than covering it.
 *
 * Directory members never become one: they are dropped during validation, since they say nothing
 * the file names do not and {@link \Phpanta\Support\Directory::create()} makes parents anyway.
 */
final readonly class UpdateFile
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param UpdateRoot $root The root this name falls under, resolved and no longer in question.
     * @param string $name The payload's own name for it, prefix included — what the report prints
     *                     and what the mirror compares against, so it is kept rather than split.
     * @param string $contents The bytes to write.
     */
    public function __construct(
        public UpdateRoot $root,
        public string     $name,
        public string     $contents,
    ) {}
}
