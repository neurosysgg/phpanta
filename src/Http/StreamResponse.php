<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Closure;
use NoDiscard;
use Phpanta\Support\Collection;

/**
 * The StreamResponse class. A body generated as it is sent: a large CSV, server-sent events.
 *
 * For a body that should never exist in memory whole, and is not a file that {@link FileResponse}
 * could read a chunk at a time. The caller hands over a closure that yields the body in chunks, and
 * the {@link StreamBody} runs it at send time, flushing after each chunk. Whether a chunk reaches the
 * client before the next one is made depends on the host's buffering — see {@link StreamBody}.
 *
 * **No `Content-Length`**, because the length is not known until the last chunk has been made. The
 * server frames the body itself (chunked on HTTP/1.1, frames on HTTP/2).
 *
 * **`Cache-Control: no-store` unless the caller says otherwise.** A body made as it goes can be
 * different the next time, and a stream cut off halfway is a body a cache would otherwise keep as
 * if it were whole. A caller that supplies its own `Cache-Control` replaces this one rather than
 * adding a second, conflicting header.
 *
 * **A HEAD never runs the closure.** The same reasoning as {@link FileResponse}, and stronger here:
 * the body is work to produce, and an event stream ends only when the client leaves. A HEAD answered
 * with that body would never be answered at all.
 */
readonly class StreamResponse implements Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param HttpStatusCode              $status  The HTTP status code.
     * @param MimeType                    $type    What the chunks add up to —
     *                                             `new MimeType(TopLevelType::Text, 'csv')`,
     *                                             `new MimeType(TopLevelType::Text, 'event-stream')`.
     * @param Closure(): iterable<string> $chunks  Makes the body. Called at send time, once per
     *                                             send, and never for a HEAD.
     * @param Collection<Header>          $headers Extra headers, in the position every other
     *                                             response here takes them.
     */
    public function __construct(
        private HttpStatusCode $status,
        private MimeType       $type,
        private Closure        $chunks,
        private Collection     $headers = new Collection(Header::class),
    ) {}

    /**
     * The status, the type, `Cache-Control` unless the caller sent one, the extra headers, and the
     * body. For a HEAD, no body.
     *
     * @param Request $request
     * @return Answer
     */
    #[NoDiscard(
        'answer() works out what would be sent and sends nothing; a call whose result goes nowhere '
        . 'answered no one',
    )]
    public function answer(Request $request): Answer
    {
        $headers = new Collection(Header::class)->with(new Header(ResponseHeader::ContentType, $this->type));

        $cacheControl = $this->headers->first(
            static fn(Header $header): bool => $header->name === ResponseHeader::CacheControl,
        );

        if ($cacheControl === null) {
            $headers = $headers->with(
                new Header(ResponseHeader::CacheControl, CacheControl::of(CacheDirective::NoStore)),
            );
        }

        return new Answer(
            $this->status,
            $headers->with(...$this->headers),
            $request->method() === HttpMethod::Head ? new TextBody() : new StreamBody($this->chunks),
        );
    }
}
