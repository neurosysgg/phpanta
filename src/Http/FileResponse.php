<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Support\Collection;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\File;

/**
 * The FileResponse class. Sends a file from disk, in whole or in the part that was asked for.
 *
 * The only response here whose body is not something this code rendered a moment earlier, and it
 * exists for one reason: a demo's audio lives under `data/`, outside the webroot, so the gate that
 * covers the page covers the bytes too. Apache never sees those files; this is the only way to
 * them. That is the difference between a demo and a release — a release redirects to a HiDrive
 * share URL, which keeps working for whoever it is forwarded to, long after the password changed.
 *
 * **Ranges are the reason this is more than `readfile()`.** An `<audio>` element seeks by asking
 * for a byte range, so a server that answers every request with the whole file gives you a player
 * that plays and will not skip — a control that looks broken, with nothing anywhere saying why.
 * {@link ByteRange} reads the ask, {@link ContentRange} states the answer, and
 * {@link ResponseHeader::AcceptRanges} is what tells the element to offer the scrubber at all.
 *
 * **It never sends a validator and never answers a 304**, the same decision
 * {@link \NeuroSYS\Controller\StatsController} takes for the same reason: this is reached by
 * handing over a password, so it says `no-store, private` and there is nothing to revalidate
 * against. Adding an `ETag` to a response we just asked not to be stored would be arguing with
 * ourselves — and here it would also be arguing with the range, since a validator on a partial
 * body is a claim about the whole one.
 */
readonly class FileResponse implements Response
{
    /**
     * How much is read from disk and flushed at a time.
     *
     * The whole point of a chunk is that a 6 MB body never exists in PHP's memory as a string; 256
     * KB is small enough for that to hold on the shared host this deploys to and large enough that
     * a full file is a couple of dozen reads rather than thousands.
     */
    private const int CHUNK = 262144;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File     $file    The file to send. Must exist — the caller decides what an absent
     *                          one means, and for a demo track that is a 404 rather than a 500.
     * @param MimeType $type    What the bytes are. {@link MimeType::forAudio()} builds it from the
     *                          extension and refuses one it does not know, because `nosniff` means
     *                          a wrong answer here cannot be corrected by the browser.
     * @param Collection<Header> $headers Extra headers, in the position every other response here takes
     *                          them. The demo routes pass {@link RobotsPolicy}.
     */
    public function __construct(
        private File     $file,
        private MimeType $type,
        private Collection $headers = new Collection(Header::class),
    ) {}

    /**
     * Sends the file, or the part of it the request asked for.
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
     * @param Request $request
     * @return void
     */
    public function send(Request $request): void
    {
        $size  = $this->file->size();
        $range = $request->range($size);

        if ($range !== null && !$range->isSatisfiable()) {
            $this->sendHeaders(HttpStatusCode::RangeNotSatisfiable, 0, ContentRange::unsatisfiable($size));

            return;
        }

        $length = $range?->length() ?? $size;

        $this->sendHeaders(
            $range === null ? HttpStatusCode::Ok : HttpStatusCode::PartialContent,
            $length,
            $range === null ? null : ContentRange::of($range),
        );

        // A HEAD asks what a GET would answer, not for the answer. The site's other responses echo
        // regardless and let the server drop the body; here the body is a file read off disk, so
        // not reading it is worth the one condition.
        if ($request->method() === HttpMethod::Head) {
            return;
        }

        $this->stream($range?->first ?? 0, $length);
    }

    /**
     * Everything that goes out before the first byte of body.
     *
     * @param HttpStatusCode    $status
     * @param int               $length The length of what is about to be sent, not of the file.
     * @param ContentRange|null $range  Which part that is, where it is a part.
     * @return void
     */
    private function sendHeaders(HttpStatusCode $status, int $length, ?ContentRange $range): void
    {
        http_response_code($status->value);

        $headers = new Collection(Header::class)->with(
            new Header(ResponseHeader::ContentType, $this->type),
            new Header(ResponseHeader::ContentLength, new ContentLength($length)),
            new Header(ResponseHeader::AcceptRanges, AcceptRanges::Bytes),
            new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
        );

        if ($range !== null) {
            $headers = $headers->with(new Header(ResponseHeader::ContentRange, $range));
        }

        foreach ($headers->with(...$this->headers) as $header) {
            header($header->line());
        }
    }

    /**
     * Reads $length bytes from $offset and writes them out, a chunk at a time.
     *
     * `fread` is asked for the smaller of the chunk and what is left, so the last read does not
     * overshoot a range's end — the difference between a 206 whose body matches its
     * `Content-Length` and one that does not, which a browser treats as a broken response rather
     * than as extra.
     *
     * @param int $offset
     * @param int $length
     * @return void
     */
    private function stream(int $offset, int $length): void
    {
        // Muted, and for the reason File::read() is: a caller has already asked `exists()`, which
        // is `is_file()` and says nothing about whether the file can be *read*. An unreadable one
        // makes fopen() warn, and by the time this runs the headers have gone out — so the warning
        // would be printed into the audio, which is the same trap that once put an E_WARNING ahead
        // of a page's doctype. Failing to open is answered with no body at all.
        $handle = Diagnostics::muted(fn(): mixed => fopen($this->file->path, 'rb'));

        if ($handle === false) {
            return;
        }

        fseek($handle, $offset);

        while ($length > 0 && !feof($handle)) {
            $chunk = fread($handle, min(self::CHUNK, $length));

            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
            $length -= strlen($chunk);
            flush();
        }

        fclose($handle);
    }
}
