<?php

declare(strict_types=1);

namespace Phpanta\Model\Update;

use JsonException;
use Phpanta\Exception\UpdateException;

/**
 * The RollbackManifest class. What a rollback asks for, on top of what every signed request claims.
 *
 * One field, `apply`, for {@link UpdateManifest}'s reason and read the same way: out of the signed
 * bytes, strictly, with no default — a missing `apply` defaulting to true would be a dry run that
 * was not one. There is no `mirror`: a rollback removes exactly the files the push it undoes added,
 * and there is no second opinion for a caller to have about that.
 *
 * The key is {@link UpdateManifest::APPLY} rather than written out again, so the two actions cannot
 * come to spell their one shared field two ways.
 */
final readonly class RollbackManifest
{
    /** How deep the manifest JSON may nest. The envelope's reason, and the same number. */
    private const int MAX_DEPTH = 8;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param bool $apply False for a dry run: report what would be restored and removed, change nothing.
     */
    private function __construct(public bool $apply) {}

    /**
     * Parses a rollback's own field out of the signed manifest.
     *
     * @param string $json The exact bytes the signature was checked against.
     * @return self
     *
     * @throws UpdateException if the JSON is unreadable or `apply` is missing or not a bool.
     */
    public static function parse(string $json): self
    {
        try {
            /** @var mixed $values */
            $values = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $cause) {
            throw new UpdateException(
                'the rollback manifest is not readable JSON: ' . $cause->getMessage(),
                previous: $cause,
            );
        }

        if (!is_array($values)) {
            throw new UpdateException('the rollback manifest is not a JSON object');
        }

        $apply = $values[UpdateManifest::APPLY] ?? null;

        if (!is_bool($apply)) {
            throw new UpdateException('the rollback manifest must carry apply:bool, present and of that type');
        }

        return new self($apply);
    }
}
