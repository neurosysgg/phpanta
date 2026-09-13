<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Closure;

/**
 * The StreamBody class. A body produced while it is sent, a chunk at a time.
 *
 * The third kind of body, beside {@link TextBody} and {@link FileBody}. This one exists neither as
 * a string nor as a file, because it is still being made: the rows of an export read one at a
 * time, the events of a server-sent stream. What it holds is the closure that makes it.
 *
 * **The closure is called when the body is asked for, never before**: once by each
 * {@link self::emit()} and once by each {@link self::contents()}. A generator runs once and is then
 * spent, so calling the closure each time gives every read a fresh one. It also means nothing is
 * produced for an answer that is never sent.
 *
 * {@link self::emit()} flushes after every chunk, the same as {@link FileBody}. **Whether a flushed
 * chunk actually leaves the host early is not this code's to decide.** PHP's `output_buffering`, a
 * compressing module and a FastCGI proxy that buffers responses can each hold the bytes until they
 * are full or the request ends. The body is right either way; only its timing depends on the host.
 */
final readonly class StreamBody implements Body
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Closure(): iterable<string> $chunks Makes the body, a chunk at a time — typically a
     *                                            generator function.
     */
    public function __construct(private Closure $chunks) {}

    /**
     * Writes each chunk as it is made, flushing after each.
     *
     * @return void
     */
    public function emit(): void
    {
        foreach (($this->chunks)() as $chunk) {
            echo $chunk;
            flush();
        }
    }

    /**
     * Every chunk, joined — for a test. It runs the closure to the end, so never ask this of an
     * event stream, which has no end.
     *
     * @return string
     */
    public function contents(): string
    {
        $contents = '';

        foreach (($this->chunks)() as $chunk) {
            $contents .= $chunk;
        }

        return $contents;
    }
}
