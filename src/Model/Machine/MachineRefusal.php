<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Service\Machine\PortableProbe;

/**
 * The MachineRefusal class. The sentences the `machine` service says no in — one spelling each, for
 * every handler that has to say it.
 *
 * A caller hearing any of them is verified, so each says everything: which place, and why.
 */
final class MachineRefusal
{
    /**
     * Nothing the service reaches is at $subject — it is not there, or not under a root.
     *
     * @param string|null $subject
     * @return ApiResult
     */
    public static function nowhere(?string $subject): ApiResult
    {
        return ApiResult::refusal(
            HttpStatusCode::NotFound,
            sprintf('nothing the machine service reaches is at /%s', $subject ?? ''),
        );
    }

    /**
     * $place is not a directory, where the action needs one.
     *
     * @param MachinePath $place
     * @return ApiResult
     */
    public static function notDirectory(MachinePath $place): ApiResult
    {
        return ApiResult::refusal(HttpStatusCode::UnprocessableContent, sprintf('%s is not a directory', $place->path));
    }

    /**
     * $place is neither a file nor a directory — a device, a socket, a pipe — which the service never
     * opens.
     *
     * @param MachinePath $place
     * @return ApiResult
     */
    public static function unopened(MachinePath $place): ApiResult
    {
        return ApiResult::refusal(
            HttpStatusCode::UnprocessableContent,
            sprintf('%s is not a file the machine service opens', $place->path),
        );
    }

    /**
     * The service's user may not read $place.
     *
     * @param MachinePath $place
     * @return ApiResult
     */
    public static function unreadable(MachinePath $place): ApiResult
    {
        return ApiResult::refusal(
            HttpStatusCode::Forbidden,
            sprintf('%s, whom the machine service runs as, may not read %s', PortableProbe::user(), $place->path),
        );
    }

    /**
     * Something is already where a write would put a new entry.
     *
     * @param string $path
     * @return ApiResult
     */
    public static function taken(string $path): ApiResult
    {
        return ApiResult::refusal(HttpStatusCode::Conflict, sprintf('something is already at %s', $path));
    }

    /**
     * A write the machine would not carry out.
     *
     * @param string $what What was not done, as a sentence opening in the past tense.
     * @return ApiResult
     */
    public static function failed(string $what): ApiResult
    {
        return ApiResult::refusal(
            HttpStatusCode::InternalServerError,
            sprintf('%s — the machine service runs as %s, who may not have the right', $what, PortableProbe::user()),
        );
    }
}
