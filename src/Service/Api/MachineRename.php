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
 * The MachineRename class. `machine v1 rename/<entry>`: an entry given the name `target` gives it, in
 * the directory it is in — never moved elsewhere, so a rename cannot carry anything out of a root.
 *
 * A link is renamed as a link, never followed; a root is not an entry, and is not renamed. A write,
 * unless it is a dry run; a name already taken is refused.
 */
final readonly class MachineRename implements ApiHandler
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

        $to   = $parent->child($this->manifest->target);
        $back = new LinkSection(AdminText::BackThere, MachineAction::Files->href($parent->subject()));

        if (file_exists($to) || is_link($to)) {
            return MachineRefusal::taken($to);
        }

        $from = $entry->path;

        if (!$this->manifest->apply) {
            $lines = HealthSection::lines(null, 'a dry run: nothing was renamed', "would rename $from to $to");

            return ApiResult::of(HttpStatusCode::Ok, $lines, $back);
        }

        return Diagnostics::muted(static fn(): bool => rename($from, $to))
            ? ApiResult::of(HttpStatusCode::Ok, HealthSection::lines(null, "renamed $from to $to"), $back)
            : MachineRefusal::failed("could not rename $from to $to");
    }
}
