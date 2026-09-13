<?php

declare(strict_types=1);

namespace Phpanta\Tool\Http;

use BackedEnum;

/**
 * The FormField class. One named value in a request body.
 *
 * What an `array<string, string|FilePart>` was: a map whose keys were field names written at the
 * call site and whose values were "a string, unless it is a file". The name is a property here, so
 * a `TrackField` case reaches it as a case rather than as
 * `->value` at every call site — and the union it holds is checked when the collection takes it,
 * which is the one thing a PHP array of that shape cannot do.
 */
final readonly class FormField
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string          $name  The field's name, exactly as it goes on the wire.
     * @param string|FilePart $value Text, or a file the transport streams off disk.
     */
    public function __construct(
        public string          $name,
        public string|FilePart $value,
    ) {}

    /**
     * A field named by an enum case, which is how every field this repo sends is named.
     *
     * @param BackedEnum|string $name
     * @param string|FilePart   $value
     * @return self
     */
    public static function of(BackedEnum|string $name, string|FilePart $value): self
    {
        return new self($name instanceof BackedEnum ? (string) $name->value : $name, $value);
    }

    /**
     * Whether this field carries a file, and so forces the whole body to be multipart.
     *
     * @return bool
     */
    public function isFile(): bool
    {
        return $this->value instanceof FilePart;
    }
}
