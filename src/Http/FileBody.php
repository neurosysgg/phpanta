<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Generator;
use Phpanta\Support\File;

/**
 * The FileBody class. Part or all of a file, read off disk a chunk at a time.
 *
 * What {@link FileResponse} answers with. The whole point of a chunk is that a 6 MB body never exists
 * in PHP's memory as a string, so {@link self::emit()} writes each one and flushes it before reading
 * the next. {@link self::contents()} reads the same chunks into one string, for a test.
 */
final readonly class FileBody implements Body
{
    /**
     * How much is read from disk and flushed at a time.
     *
     * Small enough that a whole file never has to fit in memory on a shared host, and large enough
     * that a full file is a couple of dozen reads rather than thousands.
     */
    private const int CHUNK = 262144;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File $file   The file to read.
     * @param int  $offset The first byte to send.
     * @param int  $length How many bytes to send from there — the range's length, not the file's.
     */
    public function __construct(
        private File $file,
        private int  $offset,
        private int  $length,
    ) {}

    /**
     * Writes the bytes out, flushing after each chunk.
     *
     * @return void
     */
    public function emit(): void
    {
        foreach ($this->chunks() as $chunk) {
            echo $chunk;
            flush();
        }
    }

    /**
     * @return string
     */
    public function contents(): string
    {
        $contents = '';

        foreach ($this->chunks() as $chunk) {
            $contents .= $chunk;
        }

        return $contents;
    }

    /**
     * The bytes from $offset, $length of them, a chunk at a time.
     *
     * `fread` is asked for the smaller of the chunk and what is left, so the last read does not
     * overshoot a range's end — the difference between a 206 whose body matches its
     * `Content-Length` and one that does not, which a browser treats as a broken response rather
     * than as extra.
     *
     * **Opening the file is muted — {@link \Phpanta\Support\File::reading()} — and failing to is no body
     * at all.** Whoever built this asked
     * `exists()`, which is `is_file()` and says nothing about whether the file can be *read*. An
     * unreadable one makes `fopen()` warn, and by the time this runs the headers have gone out —
     * so the warning would be written into the audio, the same trap that once put an `E_WARNING`
     * ahead of a page's doctype.
     *
     * @return Generator<int, string>
     */
    private function chunks(): Generator
    {
        $handle = $this->file->reading();

        if ($handle === null) {
            return;
        }

        try {
            fseek($handle, $this->offset);
            $length = $this->length;

            while ($length > 0 && !feof($handle)) {
                $chunk = fread($handle, min(self::CHUNK, $length));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                yield $chunk;
                $length -= strlen($chunk);
            }
        } finally {
            fclose($handle);
        }
    }
}
