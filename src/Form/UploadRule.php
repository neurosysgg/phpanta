<?php

declare(strict_types=1);

namespace Phpanta\Form;

use Phpanta\Http\Upload;
use Phpanta\Text\Translatable;

/**
 * The UploadRule interface. One thing a file a field sent has to be.
 *
 * A {@link Rule} as well, so it sits in a field's one list of rules beside {@link Required}: a file
 * field's value is the name the file was sent under, which is what `Required` asks about, and a rule
 * of this kind passes that name and asks its question of the file instead. It is asked only where a
 * file arrived — whether one had to is `Required`'s question, asked once, as {@link Rule} says.
 */
interface UploadRule extends Rule
{
    /**
     * What is wrong with $upload, in words the page shows beside the field — or null, where nothing is.
     *
     * @param Upload $upload
     * @return Translatable|null
     */
    public function checkUpload(Upload $upload): ?Translatable;
}
