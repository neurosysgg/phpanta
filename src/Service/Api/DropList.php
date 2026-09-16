<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\DropAction;
use Phpanta\Http\Api\ResultSection;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Drop\DropSummary;
use Phpanta\Model\Drop\DropTerms;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Machine\LinkSection;
use Phpanta\Model\Machine\Measure;
use Phpanta\Service\Drop\DropStore;
use Phpanta\Text\AdminText;

/**
 * The DropList class. `drop v1 list`: every drop kept here, and what can be said of each without its
 * link — how large, since when, until when, and how it opens — with a way to take each away.
 *
 * Never what one holds or what it is called: both are sealed under its link, which the store never
 * kept. A read, which sweeps what has expired on its way.
 */
final readonly class DropList implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param DropStore $store
     * @param int|null  $now   A test seam.
     */
    public function __construct(private DropStore $store, private ?int $now = null) {}

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
        $drops = $this->store->summaries($this->now);

        if ($drops->isEmpty()) {
            return ApiResult::of(
                HttpStatusCode::Ok,
                HealthSection::lines(null, 'no drop is kept here'),
                new LinkSection(AdminText::DropMake, DropAction::Create->href()),
            );
        }

        $sections = [];

        foreach ($drops as $drop) {
            $sections[] = self::described($drop);
            $sections[] = new LinkSection(AdminText::DropRevokeLink, DropAction::Revoke->href($drop->id));
        }

        return ApiResult::of(HttpStatusCode::Ok, ...$sections);
    }

    /**
     * What can be said of $drop without its link.
     *
     * @param DropSummary $drop
     * @return ResultSection
     */
    private static function described(DropSummary $drop): ResultSection
    {
        return HealthSection::lines(
            $drop->id,
            sprintf('%s on disk, kept since %s', Measure::bytes($drop->bytes), Measure::moment($drop->header->created)),
            DropTerms::gone($drop->header->expires),
            DropTerms::opens($drop->header->once),
            DropTerms::needs($drop->header->locked),
        );
    }
}
