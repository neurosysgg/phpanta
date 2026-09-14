<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The HeaderName interface. The name of a header this side sends.
 *
 * Implemented by the enums that name headers that go out, so {@link Header} can format any of them
 * without knowing which list it came from — the same shape as {@link Security\CspSource}. There are
 * two lists on purpose: {@link SecurityHeader} is exhaustive and tested as such, because
 * {@link SecurityHeaders} sends exactly its cases and nothing else, and folding the rest in would
 * make that assertion meaningless. {@link ResponseHeader} is everything a response sends besides.
 * {@link RequestHeader} is not one: those are headers read, and never sent.
 */
interface HeaderName
{
    /**
     * The header's name, as it goes on the wire.
     *
     * @return string
     */
    public function headerName(): string;
}
