<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use Phpanta\Http\ContentDisposition;
use Phpanta\Http\FileResponse;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\MimeType;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RobotsPolicy;
use Phpanta\Http\StreamResponse;
use Phpanta\Support\Collection;
use Phpanta\Support\File;

/**
 * The ResultFile class. A file an admin action answers with: which one, what it is, and whether a
 * browser shows it or saves it.
 *
 * **A file that states its size goes out as a {@link FileResponse}**, whole or in the part a range
 * asked for, so a player can seek in it. **One that states none** — `/proc/cpuinfo` is zero bytes to
 * `stat()` and a page long to a read — is read, at most {@link self::MAX_UNSIZED} bytes of it, and
 * sent as it came: there is no length to offer a range against.
 */
final readonly class ResultFile
{
    /** The most a file that states no size is read to: a mebibyte, far past what one holds. */
    public const int MAX_UNSIZED = 1_048_576;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File               $file        The file, which exists.
     * @param MimeType           $type        What its bytes are.
     * @param ContentDisposition $disposition Shown or saved.
     */
    public function __construct(
        public File               $file,
        public MimeType           $type,
        public ContentDisposition $disposition,
    ) {}

    /**
     * The file as the admin answers it: kept by no cache and not to be indexed, like every answer
     * there.
     *
     * @return Response
     */
    public function response(): Response
    {
        $disposition = new Header(ResponseHeader::ContentDisposition, $this->disposition);

        if ($this->file->size() > 0) {
            // FileResponse states its own `no-store, private`, so only the rest of the admin's pair
            // is added — a second Cache-Control would be two answers to one question.
            return new FileResponse($this->file, $this->type, new Collection(Header::class)->with(
                new Header(ResponseHeader::Robots, RobotsPolicy::hide()),
                $disposition,
            ));
        }

        $file = $this->file;

        return new StreamResponse(
            HttpStatusCode::Ok,
            $this->type,
            static fn(): iterable => [(string) $file->read(self::MAX_UNSIZED)],
            AdminHeaders::with($disposition),
        );
    }
}
