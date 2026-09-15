<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\MachineAction;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Upload;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Machine\LinkSection;
use Phpanta\Model\Machine\MachineConfig;
use Phpanta\Model\Machine\MachineManifest;
use Phpanta\Model\Machine\MachinePath;
use Phpanta\Model\Machine\MachineRefusal;
use Phpanta\Model\Machine\Measure;
use Phpanta\Support\Collection;
use Phpanta\Support\File;
use Phpanta\Text\AdminText;

/**
 * The MachineUpload class. `machine v1 upload/<directory>`: files a browser sent, kept in a directory
 * under the names they were sent with.
 *
 * **The name the browser gave is the name kept**, which is the one place the framework uses it — the
 * sender is the admin, unlocked and tapped. It is still held to what a name may be here, one segment
 * with no dot segment, and a file already there is never replaced: each file sent is kept, refused,
 * or — on a dry run — said to be keepable, and the answer lists which.
 *
 * A write, unless it is a dry run. A signed call carries no files, so it keeps none.
 */
final readonly class MachineUpload implements ApiHandler
{
    /** The permissions a kept file is given: its owner writes, everyone reads. */
    private const int MODE = 0o644;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param MachineConfig      $config
     * @param string|null        $subject  The directory, as the address named it.
     * @param MachineManifest    $manifest
     * @param Collection<Upload> $uploads  What the browser sent.
     */
    public function __construct(
        private MachineConfig   $config,
        private ?string         $subject,
        private MachineManifest $manifest,
        private Collection      $uploads,
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

        if ($this->uploads->isEmpty()) {
            return ApiResult::refusal(
                HttpStatusCode::UnprocessableContent,
                'no file was sent to keep: files come from a browser, in the form this address shows it',
            );
        }

        $lines = $this->manifest->apply ? [] : ['a dry run: nothing was kept'];
        $kept  = 0;

        foreach ($this->uploads as $upload) {
            $name   = $upload->clientName();
            $target = MachinePath::isName($name) ? new File($place->child($name)) : null;
            $size   = Measure::bytes($upload->size());

            $said = match (true) {
                $target === null                     => sprintf('refused %s: not a name a file may have', $name),
                self::occupied($target)              => sprintf('refused %s: something is already there', $name),
                !$this->manifest->apply              => sprintf('would keep %s (%s)', $target->path, $size),
                $upload->keepAs($target, self::MODE) => sprintf('kept %s (%s)', $target->path, $size),
                default                              => sprintf('could not keep %s', $target->path),
            };

            $kept   += str_starts_with($said, 'kept') || str_starts_with($said, 'would') ? 1 : 0;
            $lines[] = $said;
        }

        return ApiResult::of(
            $kept > 0 ? HttpStatusCode::Ok : HttpStatusCode::UnprocessableContent,
            HealthSection::lines(null, ...$lines),
            new LinkSection(AdminText::BackThere, MachineAction::Files->href($place->subject())),
        );
    }

    /**
     * Whether something is already at $target — a file, a directory, or a link, alive or dangling.
     *
     * @param File $target
     * @return bool
     */
    private static function occupied(File $target): bool
    {
        return file_exists($target->path) || is_link($target->path);
    }
}
