<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The ByteRange class. One `Range: bytes=…` request, resolved against the size of what was asked for.
 *
 * The site had no reason for this until a demo's audio started being served by PHP rather than
 * redirected to a file host. It is not an optimisation: an `<audio>` element **seeks** by asking
 * for a byte range, so without this a listener can play a demo and cannot skip to the drop. That is
 * the quiet kind of broken — the page works, the control just does not.
 *
 * **Understanding a header and being able to satisfy it are different questions**, and this answers
 * them separately because they have different status codes. {@link self::parse()} returns null for
 * a header this does not understand, which the caller answers by ignoring it and sending the whole
 * file with a 200 — explicitly allowed, and the right move for the multi-range requests nothing
 * here has a reason to assemble. {@link self::isSatisfiable()} is the other question: a range this
 * understood but that starts past the end of the file is a 416, not a 200, because answering it
 * with the whole file would look to the client like the bytes it asked for.
 *
 * Both bounds are inclusive, as the header's are. `bytes=0-0` is one byte, and that is not a
 * mistake worth papering over — it is the request a client makes to find out whether ranges work
 * at all.
 */
final readonly class ByteRange
{
    /** `bytes=` then `a-b`, `a-` or `-n`. One range: the unit is fixed and a list is not read. */
    private const string PATTERN = '/^bytes=(?:(\d+)-(\d*)|-(\d+))\z/';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $first The first byte wanted, inclusive.
     * @param int $last  The last byte wanted, inclusive. Below $first for an unsatisfiable range.
     * @param int $size  The size of the whole representation, which `Content-Range` has to state.
     */
    private function __construct(
        public int $first,
        public int $last,
        public int $size,
    ) {}

    /**
     * Reads a `Range` header, or answers null where there is nothing here to act on.
     *
     * Null covers four things a caller treats identically — an absent header, a unit that is not
     * `bytes`, a comma-separated list, and anything malformed. All four mean "send the whole file",
     * which is a legal response to any of them and the honest one: a server may always ignore a
     * `Range`. Returning null rather than guessing at a repair is the same rule
     * `HttpMethod::tryFrom()` follows in not guessing GET.
     *
     * `bytes=500-100` is malformed rather than unsatisfiable — the range is backwards, so it names
     * nothing at any size — and comes back null with the rest.
     *
     * @param string $header The raw `Range` value, or `''` if the request carried none.
     * @param int    $size   The size of the file being asked for.
     * @return self|null
     */
    public static function parse(string $header, int $size): ?self
    {
        if (preg_match(self::PATTERN, trim($header), $match) !== 1) {
            return null;
        }

        // A suffix range: the last n bytes, however big the file turns out to be. n of 0 asks for
        // nothing, which is unsatisfiable by definition rather than a request for an empty body.
        if (($match[3] ?? '') !== '') {
            $wanted = (int) $match[3];

            return $wanted === 0
                ? new self(1, 0, $size)
                : new self(max(0, $size - $wanted), $size - 1, $size);
        }

        $first = (int) $match[1];

        // An open-ended `a-` runs to the end. A closed `a-b` is clamped there too: asking past the
        // end is not an error, the answer is simply shorter than the question.
        $last = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);

        // Backwards, and so not a range at any size. Not the same as unsatisfiable, which is a
        // well-formed range this particular file is too short for.
        if ($match[2] !== '' && (int) $match[2] < $first) {
            return null;
        }

        return new self($first, $last, $size);
    }

    /**
     * True if this file actually holds the bytes asked for.
     *
     * False is a 416 and {@link ContentRange::unsatisfiable()}, never a 200 — a client that asked
     * for bytes 9000-9999 of a 300-byte file must not be handed the 300 as though they were it.
     *
     * @return bool
     */
    public function isSatisfiable(): bool
    {
        return $this->first <= $this->last && $this->first < $this->size && $this->size > 0;
    }

    /**
     * How many bytes this range covers — the `Content-Length` of the 206.
     *
     * @return int
     */
    public function length(): int
    {
        return $this->isSatisfiable() ? $this->last - $this->first + 1 : 0;
    }
}
