<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\MachineAction;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Machine\LinkSection;
use Phpanta\Model\Machine\MachineConfig;
use Phpanta\Model\Machine\MachineManifest;
use Phpanta\Model\Machine\MachinePath;
use Phpanta\Model\Machine\MachineRefusal;
use Phpanta\Support\Diagnostics;
use Phpanta\Text\AdminText;

/**
 * The MachineFolder class. `machine v1 folder/<directory>`: a directory made in a directory, under the
 * name `target` gives it. A write, unless it is a dry run; a name already taken is refused.
 */
final readonly class MachineFolder implements ApiHandler
{
    /** A new directory's permissions, before the umask: its owner and group write, everyone reads. */
    private const int MODE = 0o775;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param MachineConfig   $config
     * @param string|null     $subject  The directory it is made in, as the address named it.
     * @param MachineManifest $manifest
     */
    public function __construct(
        private MachineConfig   $config,
        private ?string         $subject,
        private MachineManifest $manifest,
    ) {}

    /**
     * @return bool
     */
    public function isWrite(): bool
    {
        return $this->manifest->apply;
    }

    /**
     * @return ApiResult
     */
    public function handle(): ApiResult
    {
        $place = MachinePath::resolve($this->config, $this->subject);

        if ($place === null) {
            return MachineRefusal::nowhere($this->subject);
        }

        if (!$place->isDirectory()) {
            return MachineRefusal::notDirectory($place);
        }

        $path = $place->child($this->manifest->target);

        if (file_exists($path) || is_link($path)) {
            return MachineRefusal::taken($path);
        }

        if (!$this->manifest->apply) {
            return ApiResult::of(
                HttpStatusCode::Ok,
                HealthSection::lines(null, 'a dry run: nothing was made', 'would make ' . $path),
                new LinkSection(AdminText::BackThere, MachineAction::Files->href($place->subject())),
            );
        }

        if (!Diagnostics::muted(static fn(): bool => mkdir($path, self::MODE))) {
            return MachineRefusal::failed('could not make ' . $path);
        }

        return ApiResult::of(
            HttpStatusCode::Ok,
            HealthSection::lines(null, 'made ' . $path),
            new LinkSection(AdminText::BackThere, MachineAction::Files->href(MachinePath::subjectOf($path))),
        );
    }
}
