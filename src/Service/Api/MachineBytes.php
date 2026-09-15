<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\ResultFile;
use Phpanta\Http\ContentDisposition;
use Phpanta\Model\Machine\MachineConfig;
use Phpanta\Model\Machine\MachinePath;
use Phpanta\Model\Machine\MachineRefusal;
use Phpanta\Model\Machine\MediaExtension;
use Phpanta\Model\Machine\MediaKind;

/**
 * The MachineBytes class. `machine v1 raw/<path>` and `download/<path>`: a file's bytes, whole or in
 * the part a range asked for.
 *
 * **Shown only by kind.** `raw` lets a browser show a picture, a recording, a film, text or a PDF
 * where it lands, typed as what it is; any other file goes out to be saved, as bytes — see
 * {@link MediaKind}. `download` is always to be saved. A read.
 */
final readonly class MachineBytes implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param MachineConfig $config
     * @param string|null   $subject The file, as the address named it.
     * @param bool          $save    Whether it is to be saved, whatever its kind.
     */
    public function __construct(private MachineConfig $config, private ?string $subject, private bool $save) {}

    /**
     * @return bool
     */
    public function isWrite(): bool
    {
        return false;
    }

    /**
     * @return ApiResult
     */
    public function handle(): ApiResult
    {
        $place = $this->subject === null ? null : MachinePath::resolve($this->config, $this->subject);

        if ($place === null) {
            return MachineRefusal::nowhere($this->subject);
        }

        if (!is_file($place->path)) {
            return MachineRefusal::unopened($place);
        }

        if (!is_readable($place->path)) {
            return MachineRefusal::unreadable($place);
        }

        $file = $place->file();
        $kind = MediaKind::of($file);

        return ApiResult::file($this->save
            ? new ResultFile($file, MediaExtension::octetStream(), ContentDisposition::attachment($file->name()))
            : new ResultFile($file, $kind->type($file), $kind->disposition($file)));
    }
}
