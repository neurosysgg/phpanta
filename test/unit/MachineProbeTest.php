<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Machine\CommandOutcome;
use Phpanta\Model\Machine\MachineArea;
use Phpanta\Model\Machine\MachineConfig;
use Phpanta\Model\Machine\MachineCounters;
use Phpanta\Model\Machine\MachineEntry;
use Phpanta\Model\Machine\MachineProcess;
use Phpanta\Model\Machine\Measure;
use Phpanta\Service\Machine\CommandRunner;
use Phpanta\Service\Machine\CpuInfo;
use Phpanta\Service\Machine\HostProbe;
use Phpanta\Service\Machine\LinuxProbe;
use Phpanta\Service\Machine\PortableProbe;
use Phpanta\Service\Machine\StreamMode;
use Phpanta\Service\Machine\UnameMode;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a machine says about itself, and running a command on it.
 *
 * **The Linux probe reads a machine this test makes**: a directory laid out as `/proc`, `/sys` and
 * `/etc` are, holding what a kernel would write there — two processors on one core and one on
 * another, a graphics card `pci.ids` names and one it does not, a sensor wired and one not, a
 * battery, two processes — so every fact asserted is one the test wrote. The three things PHP asks
 * the kernel itself — an architecture, an interface's addresses, a disk's size — are asked of this
 * machine, and asserted only as far as any machine would answer alike.
 */
#[CoversClass(LinuxProbe::class)]
#[CoversClass(PortableProbe::class)]
#[CoversClass(HostProbe::class)]
#[CoversClass(CpuInfo::class)]
#[CoversClass(UnameMode::class)]
#[CoversClass(StreamMode::class)]
#[CoversClass(CommandRunner::class)]
#[CoversClass(CommandOutcome::class)]
#[CoversClass(MachineCounters::class)]
#[CoversClass(MachineProcess::class)]
#[CoversClass(MachineEntry::class)]
#[CoversClass(Measure::class)]
final class MachineProbeTest extends TestCase
{
    private string $root = '';

    /**
     * A machine, as the files the kernel keeps about it.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->root = (string) realpath(Directory::temporary('phpanta-probe-')->path);

        $files = [
            'proc/stat'                        => "cpu  100 0 50 800 50 0 0 0 0 0\ncpu0 1 2 3 4 5 6 7 8\n",
            'proc/meminfo'                     => "MemTotal:       1024 kB\nMemFree:  100 kB\nMemAvailable:    256 kB\n"
                . "SwapTotal:       2048 kB\nSwapFree:        1024 kB\n",
            'proc/loadavg'                     => "0.52 0.58 0.59 2/300 12345\n",
            'proc/uptime'                      => "90061.5 1000.0\n",
            'proc/cpuinfo'                     => "processor\t: 0\nmodel name\t: Test CPU 9000\nphysical id\t: 0\n"
                . "core id\t\t: 0\ncpu MHz\t\t: 3400.000\n\nprocessor\t: 1\nmodel name\t: Test CPU 9000\n"
                . "physical id\t: 0\ncore id\t\t: 0\ncpu MHz\t\t: 3000.000\n\nprocessor\t: 2\n"
                . "model name\t: Test CPU 9000\nphysical id\t: 0\ncore id\t\t: 1\ncpu MHz\t\t: 4000.500\n",
            'proc/mounts'                      => "/dev/fake1 / ext4 rw 0 0\n/dev/fake1 /again ext4 rw 0 0\n"
                . "proc /proc proc rw 0 0\nserver:/x /mnt/nowhere nfs4 rw 0 0\n"
                . "/dev/fake2 /no\\040such ext4 rw 0 0\n",
            'proc/net/dev'                     => "Inter-|   Receive\n face |bytes    packets\n"
                . "    lo: 100 1 0 0 0 0 0 0 200 2 0 0 0 0 0 0\n"
                . "  eth9: 1024 10 0 0 0 0 0 0 2048 20 0 0 0 0 0 0\n",
            'proc/sys/kernel/hostname'         => "box\n",
            'proc/sys/kernel/osrelease'        => "6.1.0-test\n",
            'etc/os-release'                   => "NAME=\"Test\"\nPRETTY_NAME=\"Test Linux 1\"\n",
            'sys/class/net/eth9/operstate'     => "up\n",
            'sys/class/net/eth9/address'       => "aa:bb:cc:dd:ee:ff\n",
            'sys/class/dmi/id/sys_vendor'      => "Maker\n",
            'sys/class/dmi/id/product_name'    => "Model 1\n",
            'sys/class/dmi/id/board_vendor'    => "Board Co\n",
            'sys/class/dmi/id/board_name'      => "B1\n",
            'sys/devices/system/cpu/cpu0/cpufreq/cpuinfo_max_freq' => "4850000\n",
            'sys/class/drm/card0/device/vendor' => "0x10de\n",
            'sys/class/drm/card0/device/device' => "0x2482\n",
            'sys/class/drm/card1/device/vendor' => "0x1234\n",
            'sys/class/drm/card1/device/device' => "0xabcd\n",
            'sys/class/drm/card3/device/vendor' => "0x10de\n",
            'sys/class/drm/card3/device/device' => "0x9999\n",
            'sys/class/drm/card0-DP-1/status'  => "connected\n",
            'sys/class/drm/card2/uevent'       => '',
            'usr/share/hwdata/pci.ids'         => "# the list\n10de  NVIDIA Corporation\n"
                . "\t2482  GA104 [GeForce RTX 3070 Ti]\n"
                . "\t\t1043 8888  a subsystem\n10df  Someone Else\n\t0001  Their Card\n",
            'sys/class/hwmon/hwmon0/name'      => "k10temp\n",
            'sys/class/hwmon/hwmon0/temp1_input' => "45000\n",
            'sys/class/hwmon/hwmon0/temp1_label' => "Tctl\n",
            'sys/class/hwmon/hwmon0/temp2_input' => "0\n",
            'sys/class/hwmon/hwmon0/temp3_input' => "51500\n",
            'sys/class/power_supply/BAT0/type' => "Battery\n",
            'sys/class/power_supply/BAT0/capacity' => "72\n",
            'sys/class/power_supply/BAT0/status' => "Discharging\n",
            'sys/class/power_supply/AC/type'   => "Mains\n",
            'proc/42/stat'                     => '42 (my (odd) proc) S 1 42 42 0 -1 4194304 100 0 0 0 250 50 0 0 '
                . '20 0 1 0 12000 1000000 256 0',
            'proc/42/status'                   => "Name:\tx\nUid:\t0\t0\t0\t0\n",
            'proc/42/cmdline'                  => "php\0-r\0x\0",
            'proc/43/stat'                     => '43 (kworker) I 2 0 0 0 -1 0 0 0 0 0 0 0 0 0 20 0 1 0 5 0 0 0',
            'proc/43/status'                   => "Name:\tkworker\nUid:\t999999\t0\t0\t0\n",
            'proc/43/cmdline'                  => '',
            'proc/44/status'                   => "Name:\tgone\n",
            'proc/45/stat'                     => 'no brackets here',
        ];

        foreach ($files as $path => $contents) {
            $file = new File($this->root . '/' . $path);
            self::assertTrue($file->directory()->create());
            self::assertTrue($file->write($contents));
        }

        self::assertTrue(symlink('../../bus/pci/drivers/nvidia', $this->root . '/sys/class/drm/card0/device/driver'));
        self::assertTrue(new Directory($this->root . '/proc/self')->create());
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->root !== '') {
            UpdateFixture::removeTree($this->root);
        }
    }

    /**
     * Every area, in its order, with what the kernel's files said — a card named by `pci.ids`, a
     * sensor that is not wired left out, a mount counted once, and loopback left out of the network.
     *
     * @return void
     */
    public function testALinuxMachineIsReadOutOfItsFiles(): void
    {
        $sections = new LinuxProbe($this->root)->sections();
        $captions = $sections
            ->map(static fn(HealthSection $section): string => (string) ((array) $section->jsonSerialize())['caption'])
            ->toValues();

        self::assertSame(
            [
                MachineArea::Host->value,
                MachineArea::Hardware->value,
                MachineArea::Memory->value,
                MachineArea::Load->value,
                MachineArea::Disks->value,
                MachineArea::Network->value,
                MachineArea::Sensors->value,
                MachineArea::Battery->value,
            ],
            $captions,
        );

        $text = HealthSection::document(...$sections->toValues());

        $lines = [
            'hostname             box',
            'system               Test Linux 1',
            'kernel               6.1.0-test',
            'uptime               1 d 1 h 1 min',
            'model                Maker Model 1',
            'board                Board Co B1',
            'processor            Test CPU 9000',
            'cores                2 cores, 3 threads',
            'frequency            4.85 GHz',
            'graphics             NVIDIA Corporation GA104 [GeForce RTX 3070 Ti] (nvidia)',
            'graphics             1234:abcd',
            'graphics             NVIDIA Corporation',
            'memory               768.0 KiB of 1.0 MiB (75%)',
            'swap                 1.0 MiB of 2.0 MiB (50%)',
            'load                 0.52 0.58 0.59',
            'processes            2 running of 300',
            'eth9                 up · aa:bb:cc:dd:ee:ff · ↓ 1.0 KiB ↑ 2.0 KiB',
            'k10temp Tctl         45.0 °C',
            'k10temp temp3        51.5 °C',
            'BAT0                 72% · Discharging',
        ];

        foreach ($lines as $line) {
            self::assertStringContainsString($line, $text);
        }

        self::assertMatchesRegularExpression('#\n  / +[^\n]+ · ext4 · /dev/fake1\n#', $text);
        self::assertStringNotContainsString('/again', $text, 'a device is counted once');
        self::assertStringNotContainsString('temp2', $text, 'a sensor reading nothing is not wired');
        self::assertStringNotContainsString('  lo ', $text);
        self::assertStringNotContainsString('AC ', $text);
        self::assertStringNotContainsString('card0-DP-1', $text);
    }

    /**
     * The counters are the kernel's, summed: processor ticks less idling, memory less what is
     * available, and every interface's bytes but loopback's.
     *
     * @return void
     */
    public function testTheCountersAreTheKernelsOwn(): void
    {
        $counters = new LinuxProbe($this->root)->counters();

        self::assertSame(150, $counters->cpuBusy);
        self::assertSame(1000, $counters->cpuTotal);
        self::assertSame(786_432, $counters->memoryUsed);
        self::assertSame(1_048_576, $counters->memoryTotal);
        self::assertSame(1_048_576, $counters->swapUsed);
        self::assertSame(2_097_152, $counters->swapTotal);
        self::assertSame(1024, $counters->received);
        self::assertSame(2048, $counters->sent);
        self::assertSame(52, $counters->load);
        self::assertGreaterThan(0, $counters->time);
    }

    /**
     * Processes are read out of their directories, the largest first — a name with brackets in it
     * read to its last, a kernel thread named in brackets, and one that ended while it was read left
     * out.
     *
     * @return void
     */
    public function testProcessesAreTheLargestFirst(): void
    {
        $processes = new LinuxProbe($this->root)->processes()->toValues();

        self::assertCount(2, $processes);
        self::assertSame([42, 43], [$processes[0]->pid, $processes[1]->pid]);
        self::assertSame('php -r x', $processes[0]->command);
        self::assertSame('root', $processes[0]->owner);
        self::assertSame('S', $processes[0]->state);
        self::assertSame(1_048_576, $processes[0]->memory);
        self::assertSame(3, $processes[0]->cpu);
        self::assertSame('[kworker]', $processes[1]->command);
        self::assertSame('999999', $processes[1]->owner);
    }

    /**
     * A machine that keeps nothing from the probe still says what PHP can — and nothing it cannot.
     *
     * @return void
     */
    public function testAMachineThatSaysNothingIsStillNamed(): void
    {
        $bare  = (string) realpath(Directory::temporary('phpanta-bare-')->path);
        $probe = new LinuxProbe($bare);

        try {
            $sections = $probe->sections()->toValues();

            self::assertCount(1, $sections);
            self::assertStringContainsString('hostname             ' . gethostname(), $sections[0]->render());
            self::assertStringContainsString('system               ' . php_uname('s'), $sections[0]->render());
            self::assertSame(0, $probe->counters()->cpuTotal);
            self::assertTrue($probe->processes()->isEmpty());
        } finally {
            UpdateFixture::removeTree($bare);
        }
    }

    /**
     * Two threads of one core are one core; a machine that reports no cores has as many as threads.
     *
     * @return void
     */
    public function testCpuInfoCountsCoresOnce(): void
    {
        $flat = CpuInfo::read("processor\t: 0\nprocessor\t: 1\nmodel name\t: ARM\n");

        self::assertSame('ARM', $flat->model);
        self::assertSame(2, $flat->threads);
        self::assertSame(2, $flat->cores);
        self::assertSame(0.0, $flat->mhz);
        self::assertSame('', CpuInfo::read('')->model);
    }

    /**
     * Any machine says its name, its system, its architecture and who runs this; its load, its roots'
     * disks and its addresses where it keeps them — and nothing it has no portable way to say.
     *
     * @return void
     */
    public function testAnyMachineSaysWhatPhpCanAsk(): void
    {
        $config = MachineConfig::parse((string) json_encode(['roots' => ['/']]));
        self::assertNotNull($config);
        $probe  = new PortableProbe($config);
        $text   = HealthSection::document(...$probe->sections()->toValues());

        self::assertStringStartsWith("host\n  hostname             " . gethostname(), $text);
        self::assertStringContainsString('architecture         ' . php_uname('m'), $text);
        self::assertStringContainsString("disks\n  /", $text);
        self::assertTrue($probe->processes()->isEmpty());
        self::assertSame(0, $probe->counters()->cpuTotal);
        self::assertGreaterThan(0, $probe->counters()->time);
        self::assertNotSame('', PortableProbe::user());
        self::assertMatchesRegularExpression('/\A\d+\.\d\d \d+\.\d\d \d+\.\d\d\z/', PortableProbe::load());
        self::assertSame('', PortableProbe::disk('/no/such/place'));
        self::assertStringContainsString('127.0.0.1', PortableProbe::addresses(PortableProbe::LOOPBACK));
        self::assertSame('', PortableProbe::addresses('no-such-interface'));
        self::assertInstanceOf(LinuxProbe::class, HostProbe::for($config));
        self::assertSame(php_uname('r'), UnameMode::Release->asked());
        self::assertSame(['pipe', 'w'], StreamMode::Write->pipe());
    }

    /**
     * A command runs with the shell, in a directory, and says how it ended and what it printed on
     * each stream — stopped when it runs out of time, cut when it prints too much, and not at all
     * where it cannot start.
     *
     * @return void
     */
    public function testACommandSaysWhatItDid(): void
    {
        $runner = new CommandRunner();
        $ran    = $runner->run('pwd; printf err >&2; exit 4', $this->root);

        self::assertTrue($ran->started);
        self::assertSame(4, $ran->exit);
        self::assertSame($this->root . "\n", $ran->output);
        self::assertSame('err', $ran->errors);
        self::assertFalse($ran->stopped);
        self::assertFalse($ran->cut);

        $slow = new CommandRunner(1)->run('sleep 5', $this->root);

        self::assertTrue($slow->stopped);
        self::assertSame(-1, $slow->exit);
        self::assertLessThan(3000, $slow->milliseconds);

        $loud = $runner->run('head -c 2097152 /dev/zero; head -c 10 /dev/zero >&2', $this->root);

        self::assertTrue($loud->cut);
        self::assertSame(CommandRunner::MOST, strlen($loud->output));
        self::assertSame(10, strlen($loud->errors));

        self::assertFalse($runner->run('true', $this->root . '/no/such/place')->started);
    }
}
