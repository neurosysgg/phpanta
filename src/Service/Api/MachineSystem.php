<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\MachineAction;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Machine\StatsSection;
use Phpanta\Service\Machine\MachineProbe;

/**
 * The MachineSystem class. `machine v1 system`: the machine at a glance — its live readings first,
 * then its host, hardware, memory, load, disks, network, sensors and battery.
 *
 * A read. As data it carries the raw counters `<machine-stats>` works its readings out from; see
 * {@link StatsSection}.
 */
final readonly class MachineSystem implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param MachineProbe $probe What the machine is asked through.
     */
    public function __construct(private MachineProbe $probe) {}

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
        return ApiResult::of(
            HttpStatusCode::Ok,
            new StatsSection($this->probe->counters(), MachineAction::System->href(null)),
            ...$this->probe->sections()->toValues(),
        );
    }
}
