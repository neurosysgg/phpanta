<?php

declare(strict_types=1);

namespace Phpanta\Tool\Api;

use JsonException;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\ResultKey;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Health\Verdict;
use Phpanta\Support\Collection;
use stdClass;

/**
 * The ResultReader class. An admin answer that came back as data, read back into the result it was
 * written from.
 *
 * The signing commands ask for `application/json` and print {@link ApiResult::text()} — so what a
 * terminal shows is written by the server's own model, on this side, rather than by a second
 * formatter here that would have to be kept in step with it. The keys are the server's own
 * {@link ResultKey} cases, for the same reason. This is the one reader: the server writes a result
 * and never reads one, which is why it lives in the tooling and not under `src/`.
 *
 * **Anything that is not a result is null, never an exception.** An unverified call is answered the
 * way an absent address is — the site's own 404 page — and that is not a failure of this class but
 * the answer the command explains; the caller prints the body as it came instead.
 */
final readonly class ResultReader
{
    /**
     * The result $body is, or null where it is not one.
     *
     * @param string $body
     * @return ApiResult|null
     */
    public static function read(string $body): ?ApiResult
    {
        try {
            $decoded = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!$decoded instanceof stdClass) {
            return null;
        }

        $code     = self::field($decoded, ResultKey::Status);
        $written  = self::field($decoded, ResultKey::Sections);
        $status   = is_int($code) ? HttpStatusCode::tryFrom($code) : null;
        $sections = [];

        if ($status === null || !is_array($written)) {
            return null;
        }

        foreach ($written as $each) {
            $section = self::section($each);

            if ($section === null) {
                return null;
            }

            $sections[] = $section;
        }

        return ApiResult::of($status, ...$sections);
    }

    /**
     * One section, or null where it is not one.
     *
     * @param mixed $written
     * @return HealthSection|null
     */
    private static function section(mixed $written): ?HealthSection
    {
        if (!$written instanceof stdClass) {
            return null;
        }

        $caption = self::field($written, ResultKey::Caption);
        $lines   = self::field($written, ResultKey::Lines);
        $facts   = self::field($written, ResultKey::Facts);

        if ($caption !== null && !is_string($caption)) {
            return null;
        }

        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (!is_string($line)) {
                    return null;
                }
            }

            return HealthSection::lines($caption, ...$lines);
        }

        if (!is_array($facts)) {
            return null;
        }

        $read = [];

        foreach ($facts as $each) {
            $fact = self::fact($each);

            if ($fact === null) {
                return null;
            }

            $read[] = $fact;
        }

        return HealthSection::facts($caption, new Collection(HealthFact::class)->with(...$read));
    }

    /**
     * One fact, or null where it is not one.
     *
     * @param mixed $written
     * @return HealthFact|null
     */
    private static function fact(mixed $written): ?HealthFact
    {
        if (!$written instanceof stdClass) {
            return null;
        }

        $name    = self::field($written, ResultKey::Name);
        $value   = self::field($written, ResultKey::Value);
        $verdict = self::field($written, ResultKey::Verdict);

        if (!is_string($name) || !is_string($value)) {
            return null;
        }

        if ($verdict === null) {
            return new HealthFact($name, $value);
        }

        $judged = is_string($verdict) ? Verdict::tryFrom($verdict) : null;

        return $judged === null ? null : new HealthFact($name, $value, $judged);
    }

    /**
     * What $object holds under $key, or null where it holds nothing there.
     *
     * @param stdClass $object
     * @param ResultKey $key
     * @return mixed
     */
    private static function field(stdClass $object, ResultKey $key): mixed
    {
        return $object->{$key->value} ?? null;
    }
}
