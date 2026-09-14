<?php

declare(strict_types=1);

namespace Phpanta\Model\Api;

use Phpanta\Http\HttpStatusCode;

/**
 * Why a verified write could not spend its serial, and so ran nothing.
 *
 * Every case is reached only past the signature, so each says exactly what happened — the stance
 * {@link \Phpanta\Service\UpdateApplier} takes about everything a key holder asks for. What they
 * share is the promise that matters: **nothing was written**, because the serial is spent before the
 * write runs and none of these got that far.
 */
enum SerialRefusal: string
{
    /**
     * Another write holds the lock — or the lock beside the serial cannot be opened, which sits
     * beside a record that could not be written either.
     */
    case Busy = 'busy';

    /**
     * The serial is further ahead of this server's clock than a write's may be: recorded, it would
     * refuse every correctly timed call as stale until the clock caught up with it.
     */
    case Ahead = 'ahead';

    /**
     * A newer serial was recorded between the gate's first look and the lock being taken: another
     * write finished in between, and this one was minted against the tree before it.
     */
    case Stale = 'stale';

    /** The serial could not be recorded, so running the write would leave it replayable. */
    case Unrecorded = 'unrecorded';

    /**
     * The status the refusal is answered with.
     *
     * @return HttpStatusCode
     */
    public function status(): HttpStatusCode
    {
        return match ($this) {
            self::Busy, self::Ahead, self::Stale => HttpStatusCode::Conflict,
            self::Unrecorded                     => HttpStatusCode::InternalServerError,
        };
    }

    /**
     * The response body: one sentence, and a newline for the terminal it lands in.
     *
     * @return string
     */
    public function message(): string
    {
        return match ($this) {
            self::Busy => "another write is in progress, or the lock beside the update serial cannot be "
                . "opened — nothing was written\n",
            self::Ahead => "the signing machine's clock is ahead of the server's — nothing was written; set "
                . "its clock and sign it again\n",
            self::Stale => "a newer write was accepted while this one was being verified — nothing was "
                . "written; sign it again\n",
            self::Unrecorded => "the update serial could not be recorded, so nothing was written — this "
                . "payload would have been replayable\n",
        };
    }
}
