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
use Phpanta\Model\Machine\MachineEntry;
use Phpanta\Model\Machine\MachineManifest;
use Phpanta\Model\Machine\MachinePath;
use Phpanta\Model\Machine\MachineRefusal;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\File;
use Phpanta\Text\AdminText;

/**
 * The MachineDelete class. `machine v1 delete/<entry>`: a file, a link, or a directory with nothing in
 * it, removed.
 *
 * **Never a tree.** A directory that holds anything is refused, the way
 * {@link \Phpanta\Support\Directory::remove()} refuses to descend: a delete that recursed would be one
 * tap away from emptying a disk, and emptying a directory entry by entry is a choice each tap makes
 * again. A link is removed as a link, never what it points at; a root is not an entry, and is not
 * removed. A write, unless it is a dry run.
 */
final readonly class MachineDelete implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param MachineConfig   $config
     * @param string|null     $subject  The entry, as the address named it.
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
        $entry  = $this->subject === null ? null : MachinePath::entry($this->config, $this->subject);
        $parent = $entry?->parent();

        if ($entry === null || $parent === null) {
            return MachineRefusal::nowhere($this->subject);
        }

        $path      = $entry->path;
        $directory = is_dir($path) && !is_link($path);
        $back      = new LinkSection(AdminText::BackThere, MachineAction::Files->href($parent->subject()));

        if ($directory && !MachineEntry::in($path)->isEmpty()) {
            return ApiResult::refusal(
                HttpStatusCode::Conflict,
                sprintf('%s is not empty: only a directory with nothing in it is removed', $path),
            );
        }

        if (!$this->manifest->apply) {
            return ApiResult::of(
                HttpStatusCode::Ok,
                HealthSection::lines(null, 'a dry run: nothing was removed', 'would remove ' . $path),
                $back,
            );
        }

        $removed = $directory
            ? Diagnostics::muted(static fn(): bool => rmdir($path))
            : new File($path)->delete();

        return $removed
            ? ApiResult::of(HttpStatusCode::Ok, HealthSection::lines(null, 'removed ' . $path), $back)
            : MachineRefusal::failed('could not remove ' . $path);
    }
}
