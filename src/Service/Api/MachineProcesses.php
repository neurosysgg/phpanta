<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Machine\ProcessSection;
use Phpanta\Service\Machine\MachineProbe;

/**
 * The MachineProcesses class. `machine v1 processes`: what the machine is running, the processes
 * holding the most memory first. A read.
 */
final readonly class MachineProcesses implements ApiHandler
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
        return ApiResult::of(HttpStatusCode::Ok, new ProcessSection($this->probe->processes()));
    }
}
