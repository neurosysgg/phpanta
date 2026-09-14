<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\View\Html\AttributeName;

/**
 * The code samples' one attribute of their own.
 */
enum CodeAttribute: string implements AttributeName
{
    /** What a `<code-block>` is written in — a {@link SampleLanguage} value, which the stylesheet selects. */
    case Language = 'language';

    /**
     * @return string
     */
    public function attribute(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function isUrl(): bool
    {
        return false;
    }
}
