<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The Body interface. What an {@link Answer} carries after its headers.
 *
 * Two ways to have it, because a body is one of two things: a string this code rendered a moment
 * earlier — {@link TextBody} — or a file on disk that must never exist in memory whole —
 * {@link FileBody}. Both are asked the same two questions, and the second is the reason this is an
 * interface rather than a string: an answer can be asserted on without anything being sent.
 */
interface Body
{
    /**
     * Writes the body out. Called once, by {@link Answer::send()}, after every header.
     *
     * @return void
     */
    public function emit(): void;

    /**
     * The whole body as a string — what {@link self::emit()} would write.
     *
     * For a test, or anything else that asks what an answer says rather than sending it. A
     * {@link FileBody} reads its file into memory to answer, which is exactly what `emit()` exists
     * not to do, so nothing on the way to the wire asks this.
     *
     * @return string
     */
    public function contents(): string;
}
