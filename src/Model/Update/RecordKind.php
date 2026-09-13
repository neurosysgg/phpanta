<?php

declare(strict_types=1);

namespace Phpanta\Model\Update;

/**
 * The RecordKind enum. What a push did to one path, as the record of the previous release keeps it.
 *
 * Three cases, because a mirroring push does exactly three things to a path and a rollback has to
 * undo each differently: a file it **changed** is put back from its saved copy, a file it **added**
 * is removed, and a file the mirror **deleted** is recreated from its saved copy. A file it left
 * alone is not a kind at all — the record does not mention it, which is what keeps a rollback from
 * touching a file the push never touched.
 */
enum RecordKind: string
{
    /** The push overwrote bytes that were there. The old bytes are saved. */
    case Changed = 'changed';

    /** The push wrote a file where there was none. Nothing is saved; a rollback removes it. */
    case Added = 'added';

    /** The mirror deleted a file the payload omitted. Its bytes are saved. */
    case Deleted = 'deleted';

    /**
     * Whether the record keeps a copy of the bytes that were there before the push.
     *
     * @return bool
     */
    public function saves(): bool
    {
        return $this !== self::Added;
    }
}
