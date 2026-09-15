<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Machine\DirectorySection;
use Phpanta\Model\Machine\FileSection;
use Phpanta\Model\Machine\MachineConfig;
use Phpanta\Model\Machine\MachinePath;
use Phpanta\Model\Machine\MachineRefusal;

/**
 * The MachineFiles class. `machine v1 files/<path>`: a directory's entries, or what a file holds —
 * and, with no path, the one root, or the roots to choose from. A read.
 */
final readonly class MachineFiles implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param MachineConfig $config
     * @param string|null   $subject The place, as the address named it.
     */
    public function __construct(private MachineConfig $config, private ?string $subject) {}

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
        if ($this->subject === null && $this->config->roots()->count() > 1) {
            return ApiResult::of(HttpStatusCode::Ok, DirectorySection::roots($this->config));
        }

        $place = MachinePath::resolve($this->config, $this->subject);

        if ($place === null) {
            return MachineRefusal::nowhere($this->subject);
        }

        if ($place->isDirectory()) {
            return is_readable($place->path)
                ? ApiResult::of(HttpStatusCode::Ok, DirectorySection::of($place, $this->config))
                : MachineRefusal::unreadable($place);
        }

        $file = is_file($place->path) ? FileSection::of($place, $this->config) : null;

        return $file === null ? MachineRefusal::unopened($place) : ApiResult::of(HttpStatusCode::Ok, $file);
    }
}
