<?php

declare(strict_types=1);

namespace Phpanta\Tool\Http;

use Phpanta\Http\MimeType;
use Phpanta\Http\TopLevelType;
use Phpanta\Support\File;

/**
 * The FilePart class. One field of a multipart request whose value is a file on disk.
 *
 * It stays a {@link File} all the way to {@link CurlTransport}, which is the point: the master this
 * is built for is 45 MB, and a multipart body assembled in PHP would be 45 MB of string. curl
 * streams the file off disk instead, and what this class carries is only what the part's headers
 * need.
 *
 * The other half of the reason is testability. A `CURLFile` constructed here would put a curl
 * handle's private type in the middle of a value object, and a fake {@link Transport} could assert
 * nothing about it. A `FilePart` has a path, a filename and a type a test can read.
 */
final readonly class FilePart
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param File     $file     The file to send. Read by the transport, not by this.
     * @param string   $filename The name the far end is told, which need not be the name on disk.
     * @param MimeType $type     The part's `Content-Type`. Never carries a charset: audio is bytes.
     */
    public function __construct(
        public File     $file,
        public string   $filename,
        public MimeType $type,
    ) {}

    /**
     * A part for a file, named for the file unless told otherwise.
     *
     * The type is the caller's to say, because only the caller knows what the file is: a site that
     * uploads audio knows which of its formats it is, and bytes this cannot name go as bytes.
     *
     * @param File          $file
     * @param string|null   $filename Overrides the name on disk, for a working file whose name is not
     *                                the one the far end should see.
     * @param MimeType|null $type     The part's type; `application/octet-stream` where none is given.
     * @return self
     */
    public static function at(File $file, ?string $filename = null, ?MimeType $type = null): self
    {
        return new self(
            $file,
            $filename ?? $file->name(),
            $type ?? new MimeType(TopLevelType::Application, 'octet-stream', null),
        );
    }
}
