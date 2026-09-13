<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Form\Field;
use Phpanta\Form\MaxBytes;
use Phpanta\Form\Required;
use Phpanta\Form\Rule;
use Phpanta\Support\Collection;
use Phpanta\Text\Translatable;
use Phpanta\Text\Translation;
use Phpanta\View\Html\InputType;

/**
 * A form that sends a file, for the tests: a file that has to come and may be ten bytes at most,
 * and a caption beside it.
 */
enum UploadFieldFixture: string implements Field
{
    /** The most bytes {@link self::File} may hold. */
    public const int MAX = 10;

    case File    = 'file';
    case Caption = 'caption';

    /**
     * @return Translatable
     */
    public function label(): Translatable
    {
        return match ($this) {
            self::File    => new Translation(en: 'A file', de: 'Eine Datei'),
            self::Caption => new Translation(en: 'Caption', de: 'Bildunterschrift'),
        };
    }

    /**
     * @return InputType
     */
    public function type(): InputType
    {
        return match ($this) {
            self::File    => InputType::File,
            self::Caption => InputType::Text,
        };
    }

    /**
     * @return Collection<Rule>
     */
    public function rules(): Collection
    {
        $rules = new Collection(Rule::class);

        return match ($this) {
            self::File    => $rules->with(new Required(), new MaxBytes(self::MAX)),
            self::Caption => $rules,
        };
    }
}
