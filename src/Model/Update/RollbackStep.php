<?php

declare(strict_types=1);

namespace Phpanta\Model\Update;

/**
 * The RollbackStep enum. What a rollback does with one recorded path, given what is there now.
 *
 * **The fourth case is the one that makes a rollback safe to run at all.** A record describes two
 * states of a path — the bytes before the push and the bytes the push wrote — and a rollback only
 * ever moves a path from the second to the first. A path in *neither* state has been changed by
 * something else since, a full deploy or a later hand edit, and putting the old bytes back there
 * would mix two releases. One such path refuses the whole rollback before anything is written.
 * See {@link RecordEntry::step()}.
 */
enum RollbackStep: string
{
    /** The path holds what the push wrote, or nothing where the push deleted: put the old bytes back. */
    case Restore = 'restore';

    /** The path holds a file the push added: remove it. */
    case Remove = 'remove';

    /** The path is already as it was before the push — a write that never landed, say. Leave it alone. */
    case Keep = 'keep';

    /** The path is in neither state. Refuse the rollback. */
    case Conflict = 'conflict';
}
