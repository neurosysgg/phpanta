<?php

declare(strict_types=1);

namespace Phpanta\Http;

use JsonException;
use JsonSerializable;
use NoDiscard;
use Phpanta\Exception\JsonEncodingException;
use Phpanta\Support\Collection;

/**
 * The JsonResponse class. A value written as JSON, for an endpoint that a script reads.
 *
 * **The value is a {@link JsonSerializable}, never a bare array.** This follows the same reasoning
 * as a header value being a {@link HeaderValue} rather than a string. The caller names the shape of
 * what goes on the wire as a class, and its `jsonSerialize()` is the one place that says which
 * fields go out. An array assembled at the call site would let a misspelled key silently rename a
 * field for every client, and a bare array in a declared type is what the framework's guidelines
 * refuse anyway.
 *
 * **The encoding is fixed, and each flag is a decision:**
 *
 * - `JSON_THROW_ON_ERROR`: without it, a value that cannot encode comes back as `false`, and
 *   `false` written as a body is an empty 200. The error is thrown as a
 *   {@link JsonEncodingException} that keeps the cause.
 * - `JSON_UNESCAPED_SLASHES`: `\/` exists so JSON can sit inside a `<script>` element. This body
 *   never does; it is its own document, typed `application/json`, under `nosniff`.
 * - `JSON_UNESCAPED_UNICODE`: JSON is UTF-8 by definition (RFC 8259 §8.1), and `ü` is six
 *   bytes saying what two already say. U+2028 and U+2029 stay escaped, as PHP does unless told
 *   otherwise, for any reader that still evaluates JSON as JavaScript.
 * - `JSON_PRESERVE_ZERO_FRACTION`: `1.0` written as `1` is a float that arrives as an integer, so
 *   a client typed against the field would see its type change with its value.
 *
 * **The value is encoded in {@link self::answer()}, not in the body.** A value that cannot encode
 * then throws when the answer is asked for, before any status or header has gone out, where
 * {@link \Phpanta\App::run()} can still answer it as the fault it is. Encoding at send time would
 * only find out after the 200 had been sent.
 *
 * **A HEAD carries the body too**, as {@link PlainTextResponse}'s does, and the server drops it. The
 * value is already in memory, so skipping the encoding saves nothing. Encoding anyway is also what
 * makes a HEAD a 500 wherever the GET would be one.
 *
 * **`Cache-Control: no-cache` unless the caller says otherwise.** An endpoint's answer usually
 * reports state that changes, so a cache may keep it but must ask first. A caller that knows better
 * — {@link CacheControl::doNotStore()} for an answer behind a password — supplies its own, which
 * replaces this one rather than going out beside it. No `Vary` is sent: the body does not depend
 * on any request header.
 */
readonly class JsonResponse implements Response
{
    /** The encoding, fixed — see the class docblock for why each flag is there. */
    private const int FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param JsonSerializable   $value   What the body says. Encoded when the answer is asked for.
     * @param HttpStatusCode     $status  The HTTP status code.
     * @param Collection<Header> $headers Extra headers, in the position every other response here
     *                                    takes them.
     */
    public function __construct(
        private JsonSerializable $value,
        private HttpStatusCode   $status = HttpStatusCode::Ok,
        private Collection       $headers = new Collection(Header::class),
    ) {}

    /**
     * The status, `Content-Type: application/json`, `Cache-Control` unless the caller sent one, the
     * extra headers, and the value as JSON.
     *
     * @param Request $request
     * @return Answer
     *
     * @throws JsonEncodingException if the value cannot be written as JSON.
     */
    #[NoDiscard(
        'answer() works out what would be sent and sends nothing; a call whose result goes nowhere '
        . 'answered no one',
    )]
    public function answer(Request $request): Answer
    {
        try {
            $json = json_encode($this->value, self::FLAGS);
        } catch (JsonException $cause) {
            throw new JsonEncodingException(
                sprintf('JsonResponse cannot write %s as JSON: %s', $this->value::class, $cause->getMessage()),
                previous: $cause,
            );
        }

        $headers = new Collection(Header::class)->with(new Header(ResponseHeader::ContentType, MimeType::json()));

        $cacheControl = $this->headers->first(
            static fn(Header $header): bool => $header->name === ResponseHeader::CacheControl,
        );

        if ($cacheControl === null) {
            $headers = $headers->with(new Header(ResponseHeader::CacheControl, CacheControl::revalidate()));
        }

        return new Answer($this->status, $headers->with(...$this->headers), new TextBody($json));
    }
}
