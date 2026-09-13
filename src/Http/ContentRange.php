<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The ContentRange class. Which part of a file a partial response is, and how big the whole is.
 *
 * Two grammars for two answers, which is exactly why this is a type and not a `sprintf` at the
 * call site. A 206 states the part it is sending, `bytes 0-1023/5000`; a 416 sent nothing, so it
 * writes an asterisk where the range would go and states only the size. Same header name, two
 * shapes, told apart by one character that is easy to write and easier to forget.
 * {@link self::of()} and {@link self::unsatisfiable()} name the two, so a call site cannot pick
 * the wrong one silently.
 *
 * The size after the slash is never an asterisk. A server may write one where it does not know how
 * big the whole is; this one always does, because the file is on disk in front of it.
 */
final readonly class ContentRange implements HeaderValue
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param ByteRange|null $range The part being sent, or null for a range that could not be met.
     * @param int            $size  The size of the whole file.
     */
    private function __construct(private ?ByteRange $range, private int $size) {}

    /**
     * The part a 206 is sending: `bytes 0-1023/5000`.
     *
     * @param ByteRange $range
     * @return self
     */
    public static function of(ByteRange $range): self
    {
        return new self($range, $range->size);
    }

    /**
     * What a 416 says instead: nothing was sent, and here is how long the file is so the client can
     * work out what it should have asked for. Renders as `bytes` then an asterisk, then the size.
     *
     * @param int $size
     * @return self
     */
    public static function unsatisfiable(int $size): self
    {
        return new self(null, $size);
    }

    /**
     * Returns the header value: `bytes 0-1023/5000`, or an asterisk in place of the range when
     * there was none to send.
     *
     * @return string
     */
    public function render(): string
    {
        $part = $this->range === null ? '*' : $this->range->first . '-' . $this->range->last;

        return 'bytes ' . $part . '/' . $this->size;
    }
}
