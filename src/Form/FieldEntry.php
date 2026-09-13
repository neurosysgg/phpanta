<?php

declare(strict_types=1);

namespace Phpanta\Form;

use Phpanta\Text\Translatable;

/**
 * The FieldEntry class. What one field of a {@link Submission} holds: the value as it was sent, and
 * what its rules said of it.
 */
final readonly class FieldEntry
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string            $value The value as sent, `''` when nothing was.
     * @param Translatable|null $error The first rule's refusal, or null where every rule passed.
     */
    public function __construct(public string $value, public ?Translatable $error = null) {}
}
