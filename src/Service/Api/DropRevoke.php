<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\DropAction;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Drop\DropManifest;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Machine\LinkSection;
use Phpanta\Service\Drop\DropStore;
use Phpanta\Text\AdminText;

/**
 * The DropRevoke class. `drop v1 revoke/<id>`: a drop taken away before it expires, so its link opens
 * nothing from now on.
 *
 * The drop is named after the action, as the listing links it. A write, unless it is a dry run.
 */
final readonly class DropRevoke implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param DropStore    $store
     * @param string|null  $subject  The drop's id, as the address named it.
     * @param DropManifest $manifest
     */
    public function __construct(
        private DropStore    $store,
        private ?string      $subject,
        private DropManifest $manifest,
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
        $id = (string) $this->subject;

        if (!$this->store->has($id)) {
            return ApiResult::refusal(HttpStatusCode::NotFound, sprintf('no drop is kept as %s', $id));
        }

        $back = new LinkSection(AdminText::DropsKept, DropAction::List->href());

        if (!$this->manifest->apply) {
            return ApiResult::of(
                HttpStatusCode::Ok,
                HealthSection::lines(null, 'a dry run: the drop is still kept', 'would take away ' . $id),
                $back,
            );
        }

        return $this->store->revoke($id)
            ? ApiResult::of(HttpStatusCode::Ok, HealthSection::lines(null, 'took away ' . $id), $back)
            : ApiResult::refusal(HttpStatusCode::InternalServerError, 'could not take away ' . $id);
    }
}
