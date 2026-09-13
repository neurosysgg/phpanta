<?php

declare(strict_types=1);

namespace Phpanta\Http;

use NoDiscard;
use Phpanta\Support\Collection;
use Phpanta\Support\File;

/**
 * The FileResponse class. Sends a file from disk, in whole or in the part that was asked for.
 *
 * The only response here whose body is not something this code rendered a moment earlier, and it
 * exists for one reason: a file kept under `data/`, outside the webroot, is covered by the gate
 * that covers the page linking to it. The web server never sees such a file; this is the only way
 * to it. That is the difference from redirecting to a file host, whose share URL keeps working for
 * whoever it is forwarded to, long after the password changed.
 *
 * **Ranges are the reason this is more than `readfile()`.** An `<audio>` element seeks by asking
 * for a byte range, so a server that answers every request with the whole file gives you a player
 * that plays and will not skip — a control that looks broken, with nothing anywhere saying why.
 * {@link ByteRange} reads the ask, {@link ContentRange} states the answer, and
 * {@link ResponseHeader::AcceptRanges} is what tells the element to offer the scrubber at all. The
 * bytes themselves are a {@link FileBody}, read a chunk at a time.
 *
 * **It never sends a validator and never answers a 304**, the same decision any page behind a
 * password takes for the same reason: this is reached by
 * handing over a password, so it says `no-store, private` and there is nothing to revalidate
 * against. Adding an `ETag` to a response we just asked not to be stored would be arguing with
 * ourselves — and here it would also be arguing with the range, since a validator on a partial
 * body is a claim about the whole one.
 */
readonly class FileResponse implements Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param File     $file    The file to send. Must exist — the caller decides what an absent
     *                          one means, and for a gated file that is a 404 rather than a 500.
     * @param MimeType $type    What the bytes are. {@link MimeType::forAudio()} builds it from the
     *                          extension and refuses one it does not know, because `nosniff` means
     *                          a wrong answer here cannot be corrected by the browser.
     * @param Collection<Header> $headers Extra headers, in the position every other response here takes
     *                          them. A gated route passes {@link RobotsPolicy::hide()}.
     */
    public function __construct(
        private File     $file,
        private MimeType $type,
        private Collection $headers = new Collection(Header::class),
    ) {}

    /**
     * The file, or the part of it the request asked for.
     *
     * Three answers, and which one is sent is entirely {@link ByteRange}'s to decide:
     *
     * - **no range, or one this does not read** → 200 and the whole file. Ignoring a `Range` is
     *   always allowed, which is what makes "does not read it" a safe answer rather than a gap.
     * - **a range this file holds** → 206, the bytes, and a `Content-Range` naming them.
     * - **a range it does not** → 416 and no body, with `Content-Range` stating the real size so
     *   the client can work out what it should have asked for. Deliberately not a 200: handing
     *   over the whole file would look to the client like the part it asked for.
     *
     * A `Range` is read on a GET and on nothing else, so a HEAD is answered as the GET without one
     * would be — see {@link Request::range()}.
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
        $size  = $this->file->size();
        $range = $request->range($size);

        if ($range !== null && !$range->isSatisfiable()) {
            return new Answer(
                HttpStatusCode::RangeNotSatisfiable,
                $this->headers(0, ContentRange::unsatisfiable($size)),
            );
        }

        $length = $range?->length() ?? $size;

        return new Answer(
            $range === null ? HttpStatusCode::Ok : HttpStatusCode::PartialContent,
            $this->headers($length, $range === null ? null : ContentRange::of($range)),
            // A HEAD asks what a GET would answer, not for the answer. The other responses keep
            // their body and let the server drop it; here the body is a file read off disk, so not
            // reading it is worth the one condition.
            $request->method() === HttpMethod::Head
                ? new TextBody()
                : new FileBody($this->file, $range?->first ?? 0, $length),
        );
    }

    /**
     * Everything that goes out before the first byte of body.
     *
     * @param int               $length The length of what is about to be sent, not of the file.
     * @param ContentRange|null $range  Which part that is, where it is a part.
     * @return Collection<Header>
     */
    private function headers(int $length, ?ContentRange $range): Collection
    {
        $headers = new Collection(Header::class)->with(
            new Header(ResponseHeader::ContentType, $this->type),
            new Header(ResponseHeader::ContentLength, new ContentLength($length)),
            new Header(ResponseHeader::AcceptRanges, AcceptRanges::Bytes),
            new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
        );

        if ($range !== null) {
            $headers = $headers->with(new Header(ResponseHeader::ContentRange, $range));
        }

        return $headers->with(...$this->headers);
    }
}
