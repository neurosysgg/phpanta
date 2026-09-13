<?php

declare(strict_types=1);

namespace Phpanta\Model\Update;

use JsonException;
use Phpanta\Exception\UpdateException;

/**
 * The ApplyManifest class. What a write with no body asks for, on top of what every signed request
 * claims: a rollback, a probe.
 *
 * One field, `apply`, for {@link UpdateManifest}'s reason and read the same way: out of the signed
 * bytes, strictly, with no default — a missing `apply` defaulting to true would be a dry run that
 * was not one. There is no `mirror`: a rollback removes exactly the files the push it undoes added,
 * a probe removes exactly the ones it made, and there is no second opinion for a caller to have
 * about either.
 *
 * The key is {@link UpdateManifest::APPLY} rather than written out again, so the actions cannot
 * come to spell their one shared field two ways.
 */
final readonly class ApplyManifest
{
    /** How deep the manifest JSON may nest. The envelope's reason, and the same number. */
    private const int MAX_DEPTH = 8;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param bool $apply False for a dry run: report what would be done, change nothing.
     */
    private function __construct(public bool $apply) {}

    /**
     * Parses the one field out of the signed manifest.
     *
     * @param string $json The exact bytes the signature was checked against.
     * @param string $action The action's own word, for the sentence a refusal is reported in.
     * @return self
     *
     * @throws UpdateException if the JSON is unreadable or `apply` is missing or not a bool.
     */
    public static function parse(string $json, string $action): self
    {
        try {
            /** @var mixed $values */
            $values = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $cause) {
            throw new UpdateException(
                sprintf('the %s manifest is not readable JSON: %s', $action, $cause->getMessage()),
                previous: $cause,
            );
        }

        if (!is_array($values)) {
            throw new UpdateException(sprintf('the %s manifest is not a JSON object', $action));
        }

        $apply = $values[UpdateManifest::APPLY] ?? null;

        if (!is_bool($apply)) {
            throw new UpdateException(
                sprintf('the %s manifest must carry apply:bool, present and of that type', $action),
            );
        }

        return new self($apply);
    }
}
