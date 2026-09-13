<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The Header class. One response header, ready to send.
 *
 * Not a raw `'Allow: GET, HEAD'` — a string carrying both the name and the value, with nothing
 * checking either. The `Name: value` formatting happens here rather than at each `header()` call,
 * and **both halves are typed**: a {@link HeaderName} case and a {@link HeaderValue}.
 *
 * The value is typed as well as the name because a value has a *grammar* — a quoted ETag, a
 * comma-separated method list, `Basic realm="…"`, `max-age=…; includeSubDomains` — and a
 * `new Header(…)` call site is the one place a grammar cannot be checked. See {@link HeaderValue},
 * and docs/security.md.
 */
final readonly class Header
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param HeaderName  $name  The header to send.
     * @param HeaderValue $value Its value, as the object that knows how to write it.
     */
    public function __construct(
        public HeaderName  $name,
        public HeaderValue $value,
    ) {}

    /**
     * Formats the header for {@link \header()}: `Name: value`.
     *
     * @return string
     */
    public function line(): string
    {
        return $this->name->headerName() . ': ' . $this->value->render();
    }
}
