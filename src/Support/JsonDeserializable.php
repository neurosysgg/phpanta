<?php

declare(strict_types=1);

namespace Phpanta\Support;

/**
 * The JsonDeserializable interface. Counterpart to PHP's built-in {@link \JsonSerializable}.
 *
 * Implement this alongside {@link \JsonSerializable} to provide a symmetric JSON codec:
 * json_encode($object) serializes via jsonSerialize(); fromJson($string) deserializes back.
 */
interface JsonDeserializable
{
    /**
     * Constructs an instance from a JSON string.
     *
     * @param string $json A JSON-encoded string.
     * @return static|null The decoded instance, or null if $json is invalid or malformed.
     */
    public static function fromJson(string $json): ?static;
}
