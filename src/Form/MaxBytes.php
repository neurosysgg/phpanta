<?php

declare(strict_types=1);

namespace Phpanta\Form;

use NoDiscard;
use Phpanta\Exception\FormException;
use Phpanta\Http\Upload;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Translatable;

/**
 * The MaxBytes rule. The file a field sent is at most so many bytes.
 *
 * The site's own limit, beneath the host's: `upload_max_filesize` decides what arrives at all, and
 * a file over it never reaches a rule — {@link Form::read()} shows the same words for both, since a
 * visitor has the same thing to do about either. List {@link \Phpanta\Http\Upload::requirements()}
 * with the same number, so the host's limit is never the smaller one.
 */
final readonly class MaxBytes implements UploadRule
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $max The most bytes the file may hold.
     * @throws FormException if $max is below one — a file that may hold nothing is not a file.
     */
    public function __construct(public int $max)
    {
        if ($max < 1) {
            throw new FormException(sprintf('A file may hold at most %d bytes, which is not a size.', $max));
        }
    }

    /**
     * Passes: a file field's value is the name it was sent under, and a name has no size.
     *
     * @param string $value
     * @return Translatable|null
     */
    #[NoDiscard('check() only asks; a call whose result goes nowhere checked nothing')]
    public function check(string $value): ?Translatable
    {
        return null;
    }

    /**
     * @param Upload $upload
     * @return Translatable|null
     */
    #[NoDiscard('checkUpload() only asks; a call whose result goes nowhere checked nothing')]
    public function checkUpload(Upload $upload): ?Translatable
    {
        return $upload->size() > $this->max ? FrameworkText::FileTooLarge : null;
    }
}
