<?php

declare(strict_types=1);

namespace Phpanta\Form;

use Phpanta\Http\Upload;
use Phpanta\Text\Translatable;

/**
 * The FieldEntry class. What one field of a {@link Submission} holds: the value as it was sent, and
 * what its rules said of it — and, for a file field, the file.
 */
final readonly class FieldEntry
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string            $value  The value as sent, `''` when nothing was. A file field's is
     *                                  the name the file was sent under.
     * @param Translatable|null $error  The first rule's refusal, or null where every rule passed.
     * @param Upload|null       $upload The file a file field sent, or null for none, or any other field.
     */
    public function __construct(
        public string $value,
        public ?Translatable $error = null,
        public ?Upload $upload = null,
    ) {}
}
