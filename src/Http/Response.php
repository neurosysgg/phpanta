<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The Response interface. What a controller answers a request with.
 *
 * A response says what it is — a page, a file, a refusal, a redirect — and {@link self::answer()}
 * works out what that comes to on the wire for one request. Nothing here sends: see {@link Answer}.
 */
interface Response
{
    /**
     * What this response comes to for $request: its status, its headers and its body.
     *
     * No `#[\NoDiscard]` here: PHP resolves the attribute at the implementation, so each
     * implementation carries its own.
     *
     * @param Request $request
     * @return Answer
     */
    public function answer(Request $request): Answer;
}
