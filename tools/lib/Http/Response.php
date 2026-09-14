<?php

declare(strict_types=1);

namespace Phpanta\Tool\Http;

use JsonException;
use NoDiscard;
use Phpanta\Http\HeaderName;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Support\Collection;

/**
 * The Response class. What came back.
 *
 * **The status is an `int` and stays one.** The site's {@link HttpStatusCode} enumerates every
 * status an RFC names, which is enough for every response this repo will realistically read — but
 * `from()` on a status no RFC names is a `ValueError` thrown from the middle of an error path,
 * which is the worst place to learn that a CDN in front of an API answered with a 520. So the
 * number arrives verbatim and {@link self::code()} is the reading of it, with null for "the far end
 * said something that is not a status". Same instinct as `HttpMethod::tryFrom()` returning null
 * rather than guessing GET.
 */
final readonly class Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int                $status  The status line's code, as it arrived.
     * @param string             $body    The body, undecoded.
     * @param Collection<string> $headers Every header line of the final answer, `Name: value`, in order.
     */
    public function __construct(
        public int $status,
        public string $body,
        public Collection $headers = new Collection('string'),
    ) {}

    /**
     * The value of every $name header the answer carried, in order — a `Set-Cookie` may come twice.
     *
     * Header names are compared the way HTTP compares them, without regard to case.
     *
     * @param HeaderName $name
     * @return Collection<string>
     */
    #[NoDiscard('values() reads the headers and changes nothing; a call whose result goes nowhere asked for nothing')]
    public function values(HeaderName $name): Collection
    {
        $prefix = strtolower($name->headerName()) . ':';

        return $this->headers
            ->where(static fn(string $line): bool => str_starts_with(strtolower($line), $prefix))
            ->map(static fn(string $line): string => trim(substr($line, strlen($prefix))))
            ->settled();
    }

    /**
     * Whether the request succeeded.
     *
     * @return bool
     */
    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The status as the site's enum reads it, or null for a code no RFC names.
     *
     * @return HttpStatusCode|null
     */
    public function code(): ?HttpStatusCode
    {
        return HttpStatusCode::tryFrom($this->status);
    }

    /**
     * The body decoded as a JSON object.
     *
     * A {@link JsonBody} rather than the array `json_decode()` produced, so that every key a caller
     * reaches for is named by an enum case.
     *
     * @return JsonBody
     * @throws JsonException if the body is not JSON, or is JSON that is not an object.
     */
    public function json(): JsonBody
    {
        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new JsonException(sprintf(
                'Expected a JSON object, got %s.',
                get_debug_type($decoded),
            ));
        }

        /** @var array<string, mixed> $decoded */
        return new JsonBody($decoded);
    }
}
