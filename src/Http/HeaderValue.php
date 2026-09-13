<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The HeaderValue interface. What goes after the colon in one response header.
 *
 * The other half of {@link HeaderName}, and it arrived second for a reason worth recording: the
 * name was typed first because a misspelled header name is silent, and the value was left a string
 * because "it is just text". Both halves are just text on the wire. The difference is that a header
 * value has a *grammar* — `max-age=31536000; includeSubDomains`, `no-store, private`,
 * `Basic realm="…"`, a quoted ETag, a comma-separated method list — and a string is where a grammar
 * goes to be got wrong one call site at a time.
 *
 * Most of the implementations already existed and already had `render()`; they only ever lacked the
 * interface saying what that method was for. {@link Security\ContentSecurityPolicy},
 * {@link Security\PermissionsPolicy}, {@link Security\StrictTransportSecurity} and {@link MimeType}
 * each gained one line. The rest — {@link CacheControl}, {@link ETag}, {@link Vary}, {@link Allow},
 * {@link BasicChallenge}, {@link Location} — are the values that were still being assembled at the
 * `new Header(…)` call site, which is exactly where a grammar cannot be checked.
 *
 * `render()` rather than `__toString()`: it is the name every other object here that produces a
 * wire form already uses, and a `Stringable` would let a value be concatenated into somewhere it
 * was never checked for.
 */
interface HeaderValue
{
    /**
     * The value as it goes on the wire, after the `Name: `.
     *
     * @return string
     */
    public function render(): string;
}
