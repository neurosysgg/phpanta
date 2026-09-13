<?php

declare(strict_types=1);

namespace Phpanta\Service\Health;

use Phpanta\App;
use Phpanta\DataFileName;
use Phpanta\Model\Health\Area;
use Phpanta\Model\Health\Finding;
use Phpanta\Model\Health\Level;
use Phpanta\Model\Health\Requirement;

/**
 * The DataFileRequirement class. A file under `data/` is where the app expects it.
 *
 * **Declared for the tracked files only**, by {@link \Phpanta\Support\RequirementInitialization}:
 * the repository carries those, so every clone has them and an absence is a fault. The untracked
 * ones each have their own reason to be absent — no gate configured, no key installed, nothing
 * written yet — which is state rather than a fault, and `capability v1 deployment` reports it
 * without a verdict.
 *
 * Present is the whole check. Whether a file parses is its reader's to say, on the first page that
 * reads it; a health check that re-ran every reader would be a second, slower copy of the app.
 */
final readonly class DataFileRequirement implements Requirement
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param DataFileName $file
     */
    public function __construct(private DataFileName $file) {}

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->file->value;
    }

    /**
     * @return Area
     */
    public function area(): Area
    {
        return Area::Deployment;
    }

    /**
     * @return Level
     */
    public function level(): Level
    {
        return Level::Required;
    }

    /**
     * @return string
     */
    public function expected(): string
    {
        return 'tracked, so every clone has it';
    }

    /**
     * The size where the file is there, because a size is what tells a whole file from a
     * truncated upload.
     *
     * @return Finding
     */
    public function check(): Finding
    {
        $handle = App::current()->dataFile($this->file);

        return $handle->exists()
            ? new Finding(sprintf('%d bytes', $handle->size()), true)
            : new Finding('no file there', false);
    }
}
