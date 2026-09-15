<?php

declare(strict_types=1);

namespace Phpanta\Service\Machine;

use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Machine\MachineArea;
use Phpanta\Model\Machine\MachineCounters;
use Phpanta\Model\Machine\MachineEntry;
use Phpanta\Model\Machine\MachineFact;
use Phpanta\Model\Machine\MachineProcess;
use Phpanta\Model\Machine\Measure;
use Phpanta\Support\BareArray;
use Phpanta\Support\Collection;
use Phpanta\Support\Diagnostics;
use Phpanta\Support\Directory;
use Phpanta\Support\File;

/**
 * The LinuxProbe class. What a Linux machine says about itself, read out of `/proc` and `/sys` —
 * fastfetch's facts, and `top`'s.
 *
 * **Read, never run.** Every fact here is a file the kernel keeps: the processor in `/proc/cpuinfo`,
 * memory in `/proc/meminfo`, mounts in `/proc/mounts`, the board in `/sys/class/dmi/id/`, sensors in
 * `/sys/class/hwmon/`. Reading costs a few milliseconds and needs no program installed, no shell and
 * no right the web server's user lacks; a machine that keeps a file from that user leaves the fact
 * out rather than failing. A graphics card is named from `pci.ids` where the machine has one.
 *
 * **The root is a parameter**, `''` for the machine itself, so a test hands it a directory laid out
 * the way `/proc` and `/sys` are and asserts on facts it wrote. Three things are asked of the machine
 * itself whatever the root: the architecture, the addresses an interface has, and how full a disk is —
 * PHP asks the kernel for those directly, and there is no file to read them from.
 */
final readonly class LinuxProbe implements MachineProbe
{
    /** The kernel's clock ticks a second, which `/proc` counts processor time in. */
    private const int TICKS = 100;

    /** A memory page, in bytes — what `/proc/<pid>/stat` counts a process's memory in. */
    private const int PAGE = 4096;

    /** How many processes are listed. */
    private const int MOST_PROCESSES = 50;

    /** The filesystems a mount of that is not a device is still a disk worth reporting. */
    private const string NETWORKED = ' nfs nfs4 cifs smb3 zfs fuseblk ';

    /** What stands between two facts in one value. */
    private const string BETWEEN = ' · ';

    /**
     * The hottest a sensor may say it is and be believed, in degrees: an unwired input on a
     * motherboard's monitoring chip reads nought, or far below it, or far above anything a machine
     * survives.
     */
    private const int HOTTEST = 150;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $root Where `/proc` and `/sys` are read from: `''` for this machine.
     */
    public function __construct(private string $root = '') {}

    /**
     * @return Collection<HealthSection>
     */
    public function sections(): Collection
    {
        $sections = new Collection(HealthSection::class);
        $areas    = [
            MachineArea::Host->value     => $this->host(),
            MachineArea::Hardware->value => $this->hardware(),
            MachineArea::Memory->value   => $this->memory(),
            MachineArea::Load->value     => $this->load(),
            MachineArea::Disks->value    => $this->disks(),
            MachineArea::Network->value  => $this->network(),
            MachineArea::Sensors->value  => $this->sensors(),
            MachineArea::Battery->value  => $this->battery(),
        ];

        foreach ($areas as $caption => $facts) {
            if (!$facts->isEmpty()) {
                $sections = $sections->with(HealthSection::facts($caption, $facts));
            }
        }

        return $sections;
    }

    /**
     * @return MachineCounters
     */
    public function counters(): MachineCounters
    {
        $busy  = 0;
        $total = 0;

        foreach (explode("\n", $this->read('proc/stat') ?? '') as $line) {
            if (!str_starts_with($line, 'cpu ')) {
                continue;
            }

            // user nice system idle iowait irq softirq steal — guest time is counted in user already,
            // and idle and iowait are the two a processor spends doing nothing.
            foreach (preg_split('/\s+/', trim(substr($line, 4))) ?: [] as $index => $tick) {
                $total += $index < 8 ? (int) $tick : 0;
                $busy  += $index < 8 && $index !== 3 && $index !== 4 ? (int) $tick : 0;
            }

            break;
        }

        $meminfo  = $this->read('proc/meminfo') ?? '';
        $received = 0;
        $sent     = 0;

        foreach ($this->traffic() as [$name, $in, $out]) {
            $received += $name === PortableProbe::LOOPBACK ? 0 : $in;
            $sent     += $name === PortableProbe::LOOPBACK ? 0 : $out;
        }

        return new MachineCounters(
            cpuBusy: $busy,
            cpuTotal: $total,
            memoryUsed: self::kilobytes($meminfo, 'MemTotal') - self::kilobytes($meminfo, 'MemAvailable'),
            memoryTotal: self::kilobytes($meminfo, 'MemTotal'),
            swapUsed: self::kilobytes($meminfo, 'SwapTotal') - self::kilobytes($meminfo, 'SwapFree'),
            swapTotal: self::kilobytes($meminfo, 'SwapTotal'),
            received: $received,
            sent: $sent,
            load: (int) round((float) explode(' ', $this->read('proc/loadavg') ?? '')[0] * 100),
            time: (int) (microtime(true) * 1000),
        );
    }

    /**
     * @return Collection<MachineProcess>
     */
    public function processes(): Collection
    {
        $booted    = time() - ($this->uptime() ?? 0);
        $processes = [];

        foreach ($this->entries('proc') as $pid) {
            $process = ctype_digit($pid) ? $this->process((int) $pid, $booted) : null;

            if ($process !== null) {
                $processes[] = $process;
            }
        }

        usort($processes, static fn(MachineProcess $a, MachineProcess $b): int => $b->memory <=> $a->memory);

        return new Collection(MachineProcess::class)->with(...array_slice($processes, 0, self::MOST_PROCESSES));
    }

    /**
     * The machine's name, its system, its kernel, and how long it has been up.
     *
     * @return Collection<HealthFact>
     */
    private function host(): Collection
    {
        $uptime   = $this->uptime();
        $hostname = $this->read('proc/sys/kernel/hostname') ?? (string) gethostname();
        $kernel   = $this->read('proc/sys/kernel/osrelease') ?? UnameMode::Release->asked();
        $facts    = new Collection(HealthFact::class)->with(
            new HealthFact(MachineFact::Hostname->value, $hostname),
            new HealthFact(MachineFact::System->value, $this->system()),
            new HealthFact(MachineFact::Kernel->value, $kernel),
            new HealthFact(MachineFact::Architecture->value, UnameMode::Machine->asked()),
        );

        if ($uptime !== null) {
            $facts = $facts->with(
                new HealthFact(MachineFact::Uptime->value, Measure::duration($uptime)),
                new HealthFact(MachineFact::Booted->value, Measure::moment(time() - $uptime)),
            );
        }

        return $facts->with(new HealthFact(MachineFact::User->value, PortableProbe::user()));
    }

    /**
     * What the machine is built of: its maker's model, its board, its processor, its graphics.
     *
     * @return Collection<HealthFact>
     */
    private function hardware(): Collection
    {
        $facts = new Collection(HealthFact::class);
        $model = trim($this->read('sys/class/dmi/id/sys_vendor') . ' ' . $this->read('sys/class/dmi/id/product_name'));
        $board = trim($this->read('sys/class/dmi/id/board_vendor') . ' ' . $this->read('sys/class/dmi/id/board_name'));
        $cpu   = CpuInfo::read($this->read('proc/cpuinfo') ?? '');
        $khz   = (int) $this->read('sys/devices/system/cpu/cpu0/cpufreq/cpuinfo_max_freq');

        if ($model !== '') {
            $facts = $facts->with(new HealthFact(MachineFact::Model->value, $model));
        }

        if ($board !== '') {
            $facts = $facts->with(new HealthFact(MachineFact::Board->value, $board));
        }

        if ($cpu->model !== '') {
            $facts = $facts->with(
                new HealthFact(MachineFact::Processor->value, $cpu->model),
                new HealthFact(MachineFact::Cores->value, sprintf('%d cores, %d threads', $cpu->cores, $cpu->threads)),
            );
        }

        if ($khz > 0 || $cpu->mhz > 0) {
            $ghz   = $khz > 0 ? $khz / 1e6 : $cpu->mhz / 1e3;
            $facts = $facts->with(new HealthFact(MachineFact::Frequency->value, sprintf('%.2F GHz', $ghz)));
        }

        foreach ($this->entries('sys/class/drm') as $card) {
            $graphics = preg_match('/\Acard\d+\z/', $card) === 1 ? $this->graphics($card) : null;

            if ($graphics !== null) {
                $facts = $facts->with(new HealthFact(MachineFact::Graphics->value, $graphics));
            }
        }

        return $facts;
    }

    /**
     * Memory and swap: how much is in use, of how much.
     *
     * @return Collection<HealthFact>
     */
    private function memory(): Collection
    {
        $meminfo = $this->read('proc/meminfo');

        if ($meminfo === null) {
            return new Collection(HealthFact::class);
        }

        $total = self::kilobytes($meminfo, 'MemTotal');
        $swap  = self::kilobytes($meminfo, 'SwapTotal');
        $used  = $total - self::kilobytes($meminfo, 'MemAvailable');

        return new Collection(HealthFact::class)->with(
            new HealthFact(MachineFact::Memory->value, Measure::share($used, $total)),
            new HealthFact(
                MachineFact::Swap->value,
                $swap > 0 ? Measure::share($swap - self::kilobytes($meminfo, 'SwapFree'), $swap) : '',
            ),
        );
    }

    /**
     * The load averages, and how many processes there are.
     *
     * @return Collection<HealthFact>
     */
    private function load(): Collection
    {
        $fields = explode(' ', $this->read('proc/loadavg') ?? '');

        if (count($fields) < 4) {
            return new Collection(HealthFact::class);
        }

        [$running, $all] = array_pad(explode('/', $fields[3], 2), 2, '');

        return new Collection(HealthFact::class)->with(
            new HealthFact(MachineFact::Load->value, implode(' ', array_slice($fields, 0, 3))),
            new HealthFact(MachineFact::Processes->value, sprintf('%d running of %d', (int) $running, (int) $all)),
        );
    }

    /**
     * Every disk mounted — a device, or a filesystem over the network — once, however often it is
     * mounted, by where it is mounted.
     *
     * @return Collection<HealthFact>
     */
    private function disks(): Collection
    {
        $facts = new Collection(HealthFact::class);
        $seen  = [];

        foreach (explode("\n", $this->read('proc/mounts') ?? '') as $line) {
            [$device, $mount, $type] = array_pad(explode(' ', $line), 3, '');
            $networked               = str_contains(self::NETWORKED, ' ' . $type . ' ');
            $disk                    = str_starts_with($device, '/dev/') || $networked;

            if (isset($seen[$device]) || !$disk) {
                continue;
            }

            // The mount table escapes what would break its columns, as octal.
            $seen[$device] = true;
            $mount         = strtr($mount, ['\040' => ' ', '\011' => "\t", '\012' => "\n", '\134' => '\\']);
            $disk          = PortableProbe::disk($mount);

            if ($disk !== '') {
                $facts = $facts->with(new HealthFact($mount, implode(self::BETWEEN, [$disk, $type, $device])));
            }
        }

        return $facts;
    }

    /**
     * Every interface but loopback: its addresses, whether it is up, its hardware address, and what
     * it has moved.
     *
     * @return Collection<HealthFact>
     */
    private function network(): Collection
    {
        $facts = new Collection(HealthFact::class);

        foreach ($this->traffic() as [$name, $in, $out]) {
            if ($name === PortableProbe::LOOPBACK) {
                continue;
            }

            $parts = [
                PortableProbe::addresses($name),
                $this->read(sprintf('sys/class/net/%s/operstate', $name)) ?? '',
                $this->read(sprintf('sys/class/net/%s/address', $name)) ?? '',
                sprintf('↓ %s ↑ %s', Measure::bytes($in), Measure::bytes($out)),
            ];
            $said  = [];

            foreach ($parts as $part) {
                if ($part !== '') {
                    $said[] = $part;
                }
            }

            $facts = $facts->with(new HealthFact($name, implode(self::BETWEEN, $said)));
        }

        return $facts;
    }

    /**
     * Every temperature a sensor reports and can be believed about, by the sensor's name and the
     * reading's label.
     *
     * @return Collection<HealthFact>
     */
    private function sensors(): Collection
    {
        $facts = new Collection(HealthFact::class);

        foreach ($this->entries('sys/class/hwmon') as $monitor) {
            $sensor = $this->read(sprintf('sys/class/hwmon/%s/name', $monitor)) ?? $monitor;

            foreach (new Directory($this->root . '/sys/class/hwmon/' . $monitor)->files('temp*_input') as $input) {
                $degrees = (int) trim((string) $input->read()) / 1000;
                $stem    = substr($input->path, 0, -strlen('_input'));
                $label   = trim((string) new File($stem . '_label')->read());

                if ($degrees > 0 && $degrees <= self::HOTTEST) {
                    $facts = $facts->with(new HealthFact(
                        $sensor . ' ' . ($label === '' ? basename($stem) : $label),
                        sprintf('%.1F °C', $degrees),
                    ));
                }
            }
        }

        return $facts;
    }

    /**
     * Every battery: how full, and whether it is charging.
     *
     * @return Collection<HealthFact>
     */
    private function battery(): Collection
    {
        $facts = new Collection(HealthFact::class);

        foreach ($this->entries('sys/class/power_supply') as $supply) {
            if ($this->read(sprintf('sys/class/power_supply/%s/type', $supply)) !== 'Battery') {
                continue;
            }

            $facts = $facts->with(new HealthFact($supply, sprintf(
                '%s%%%s%s',
                $this->read(sprintf('sys/class/power_supply/%s/capacity', $supply)) ?? '?',
                self::BETWEEN,
                $this->read(sprintf('sys/class/power_supply/%s/status', $supply)) ?? '',
            )));
        }

        return $facts;
    }

    /**
     * A graphics card, by the name `pci.ids` gives its vendor and device, and the driver it runs
     * under — or null where the card says too little to name.
     *
     * @param string $card A drm card's directory name, `card0`.
     * @return string|null
     */
    private function graphics(string $card): ?string
    {
        $vendor = $this->read(sprintf('sys/class/drm/%s/device/vendor', $card));
        $id     = $this->read(sprintf('sys/class/drm/%s/device/device', $card));

        if ($vendor === null || $id === null) {
            return null;
        }

        $vendor = strtolower(substr($vendor, 2));
        $id     = strtolower(substr($id, 2));
        $link   = $this->root . '/' . sprintf('sys/class/drm/%s/device/driver', $card);
        $driver = Diagnostics::muted(static fn(): string|false => readlink($link));
        $name   = $this->pciName($vendor, $id) ?? $vendor . ':' . $id;

        return $driver === false ? $name : $name . ' (' . basename($driver) . ')';
    }

    /**
     * The vendor's and the device's names, as the machine's `pci.ids` lists them — or null where it
     * has no list, or the list does not know the vendor.
     *
     * @param string $vendor Four hex digits.
     * @param string $device Four hex digits.
     * @return string|null
     */
    private function pciName(string $vendor, string $device): ?string
    {
        $named = null;

        foreach (new File($this->root . '/usr/share/hwdata/pci.ids')->lines() as $line) {
            if ($named === null) {
                $named = str_starts_with($line, $vendor . '  ') ? substr($line, 6) : null;

                continue;
            }

            if (str_starts_with($line, "\t" . $device . '  ')) {
                return $named . ' ' . substr($line, 7);
            }

            if ($line !== '' && $line[0] !== "\t" && $line[0] !== '#') {
                break;
            }
        }

        return $named;
    }

    /**
     * One process, read out of `/proc/<pid>/` — or null where it ended while it was being read.
     *
     * @param int $pid
     * @param int $booted When the machine started, which a process's start is counted from.
     * @return MachineProcess|null
     */
    private function process(int $pid, int $booted): ?MachineProcess
    {
        $stat = $this->read(sprintf('proc/%d/stat', $pid));
        $open = $stat === null ? false : strpos($stat, '(');
        $shut = $stat === null ? false : strrpos($stat, ')');

        if ($stat === null || $open === false || $shut === false) {
            return null;
        }

        // The command's name is in brackets and may hold spaces and brackets of its own, so the
        // fields are counted from the last closing one: the state, then the rest, from the parent on.
        $name   = substr($stat, $open + 1, $shut - $open - 1);
        $fields = explode(' ', trim(substr($stat, $shut + 1)));
        $status = $this->read(sprintf('proc/%d/status', $pid)) ?? '';
        $line   = str_replace("\0", ' ', $this->read(sprintf('proc/%d/cmdline', $pid)) ?? '');
        $uid    = preg_match('/^Uid:\s+(\d+)/m', $status, $matched) === 1 ? (int) $matched[1] : -1;

        return new MachineProcess(
            $pid,
            $uid < 0 ? '?' : MachineEntry::owner($uid),
            $fields[0] ?? '?',
            (int) ($fields[21] ?? 0) * self::PAGE,
            intdiv((int) ($fields[11] ?? 0) + (int) ($fields[12] ?? 0), self::TICKS),
            $booted + intdiv((int) ($fields[19] ?? 0), self::TICKS),
            $line === '' ? '[' . $name . ']' : $line,
        );
    }

    /**
     * What the system calls itself — `os-release`'s pretty name — or the kernel's own name.
     *
     * @return string
     */
    private function system(): string
    {
        foreach (['etc/os-release', 'usr/lib/os-release'] as $release) {
            foreach (explode("\n", $this->read($release) ?? '') as $line) {
                if (str_starts_with($line, 'PRETTY_NAME=')) {
                    return trim(substr($line, strlen('PRETTY_NAME=')), '"\'');
                }
            }
        }

        return UnameMode::System->asked();
    }

    /**
     * How long the machine has been up, in seconds, or null where it will not say.
     *
     * @return int|null
     */
    private function uptime(): ?int
    {
        $uptime = $this->read('proc/uptime');

        return $uptime === null ? null : (int) explode(' ', $uptime)[0];
    }

    /**
     * Each interface in `/proc/net/dev`, with the bytes it has taken in and sent.
     *
     * @return list<array{string, int, int}>
     */
    #[BareArray('a tuple per interface — its name and two counts, the table /proc/net/dev is, parsed at its door')]
    private function traffic(): array
    {
        $traffic = [];

        foreach (explode("\n", $this->read('proc/net/dev') ?? '') as $line) {
            [$name, $counts] = array_pad(explode(':', $line, 2), 2, null);

            if ($counts === null) {
                continue;
            }

            $fields    = preg_split('/\s+/', trim($counts)) ?: [];
            $traffic[] = [trim($name), (int) ($fields[0] ?? 0), (int) ($fields[8] ?? 0)];
        }

        return $traffic;
    }

    /**
     * The names in the directory at $relative, `.` and `..` left out — none where it cannot be read.
     *
     * @param string $relative
     * @return list<string>
     */
    #[BareArray('scandir() is the door: the names under a directory of /proc or /sys, read once and walked')]
    private function entries(string $relative): array
    {
        $names = Diagnostics::muted(
            #[BareArray('scandir() answers in an array, or false: this is the door it comes through')]
            fn(): array|false => scandir($this->root . '/' . $relative),
        );

        $entries = [];

        foreach ($names === false ? [] : $names as $name) {
            if ($name !== '.' && $name !== '..') {
                $entries[] = $name;
            }
        }

        return $entries;
    }

    /**
     * The file at $relative under the root, trimmed — or null where it cannot be read.
     *
     * @param string $relative
     * @return string|null
     */
    private function read(string $relative): ?string
    {
        $text = new File($this->root . '/' . $relative)->read();

        return $text === null ? null : trim($text);
    }

    /**
     * The number of kilobytes `/proc/meminfo` gives $key, in bytes — 0 where it gives none.
     *
     * @param string $meminfo
     * @param string $key
     * @return int
     */
    private static function kilobytes(string $meminfo, string $key): int
    {
        return preg_match('/^' . $key . ':\s+(\d+)/m', $meminfo, $matched) === 1 ? (int) $matched[1] * 1024 : 0;
    }
}
