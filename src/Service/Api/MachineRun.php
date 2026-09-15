<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\MachineAction;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Machine\LinkSection;
use Phpanta\Model\Machine\MachineArea;
use Phpanta\Model\Machine\MachineConfig;
use Phpanta\Model\Machine\MachineFact;
use Phpanta\Model\Machine\MachineManifest;
use Phpanta\Model\Machine\MachinePath;
use Phpanta\Model\Machine\MachineRefusal;
use Phpanta\Model\Machine\TextSection;
use Phpanta\Service\Machine\CommandRunner;
use Phpanta\Service\Machine\PortableProbe;
use Phpanta\Support\Collection;
use Phpanta\Text\AdminText;

/**
 * The MachineRun class. `machine v1 run/<directory>`: a command line run with the machine's shell in a
 * directory, and what it printed.
 *
 * Offered only where `data/machine.json` switches commands on, and a write like any other: a browser
 * taps its passkey for each run, a signing command signs each. A dry run says what would be run,
 * where and as whom, and runs nothing. See {@link CommandRunner} for how long a command may take and
 * how much of what it prints is kept.
 */
final readonly class MachineRun implements ApiHandler
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param MachineConfig   $config
     * @param string|null     $subject  The directory it runs in, as the address named it.
     * @param MachineManifest $manifest
     * @param CommandRunner   $runner   What runs it.
     */
    public function __construct(
        private MachineConfig   $config,
        private ?string         $subject,
        private MachineManifest $manifest,
        private CommandRunner   $runner = new CommandRunner(),
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

        $back = new LinkSection(AdminText::BackThere, MachineAction::Files->href($place->subject()));

        if (!$this->manifest->apply) {
            return ApiResult::of(HttpStatusCode::Ok, HealthSection::lines(
                null,
                'a dry run: nothing was run',
                sprintf('would run %s in %s as %s', $this->manifest->command, $place->path, PortableProbe::user()),
            ), $back);
        }

        $outcome = $this->runner->run($this->manifest->command, $place->path);

        if (!$outcome->started) {
            return MachineRefusal::failed('could not start the shell in ' . $place->path);
        }

        $exit     = $outcome->stopped ? 'stopped: it ran out of time' : (string) $outcome->exit;
        $sections = [HealthSection::facts(null, new Collection(HealthFact::class)->with(
            new HealthFact(MachineFact::Command->value, $this->manifest->command),
            new HealthFact(MachineFact::Directory->value, $place->path),
            new HealthFact(MachineFact::Exit->value, $exit),
            new HealthFact(MachineFact::Took->value, sprintf('%d ms', $outcome->milliseconds)),
        ))];

        $streams = [MachineArea::Output->value => $outcome->output, MachineArea::Errors->value => $outcome->errors];

        foreach ($streams as $caption => $text) {
            if ($text !== '') {
                $sections[] = new TextSection($caption, $text);
            }
        }

        if ($outcome->cut) {
            $sections[] = HealthSection::lines(null, 'it printed more than is kept; only the start is shown');
        }

        return ApiResult::of(HttpStatusCode::Ok, ...[...$sections, $back]);
    }
}
