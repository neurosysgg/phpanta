<?php

declare(strict_types=1);

namespace Phpanta\Service\Machine;

use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Machine\MachineArea;
use Phpanta\Model\Machine\MachineConfig;
use Phpanta\Model\Machine\MachineCounters;
use Phpanta\Model\Machine\MachineEntry;
use Phpanta\Model\Machine\MachineFact;
use Phpanta\Model\Machine\MachineProcess;
use Phpanta\Model\Machine\Measure;
use Phpanta\Support\Collection;
use Phpanta\Support\Diagnostics;

/**
 * The PortableProbe class. What PHP can ask of any machine, and all a machine with no `/proc` is
 * asked: its name and system, its load, the disks its roots are on, and its network's addresses.
 *
 * Its pieces are public, so {@link LinuxProbe} asks the same questions the same way where it has no
 * better answer of its own.
 */
final readonly class PortableProbe implements MachineProbe
{
    /** What a machine calls the interface that only ever talks to itself, which is left out. */
    public const string LOOPBACK = 'lo';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param MachineConfig $config Whose roots' disks are reported.
     */
    public function __construct(private MachineConfig $config) {}

    /**
     * @return Collection<HealthSection>
     */
    public function sections(): Collection
    {
        $sections = new Collection(HealthSection::class)->with(HealthSection::facts(
            MachineArea::Host->value,
            new Collection(HealthFact::class)->with(
                new HealthFact(MachineFact::Hostname->value, (string) gethostname()),
                new HealthFact(MachineFact::System->value, self::system()),
                new HealthFact(MachineFact::Architecture->value, UnameMode::Machine->asked()),
                new HealthFact(MachineFact::User->value, self::user()),
            ),
        ));

        $load = self::load();

        if ($load !== '') {
            $sections = $sections->with(HealthSection::facts(
                MachineArea::Load->value,
                new Collection(HealthFact::class)->with(new HealthFact(MachineFact::Load->value, $load)),
            ));
        }

        $disks = new Collection(HealthFact::class);

        foreach ($this->config->roots() as $root) {
            $disk = self::disk($root->path);

            if ($disk !== '') {
                $disks = $disks->with(new HealthFact($root->path, $disk));
            }
        }

        $network = self::network();

        foreach ([MachineArea::Disks->value => $disks, MachineArea::Network->value => $network] as $caption => $facts) {
            if (!$facts->isEmpty()) {
                $sections = $sections->with(HealthSection::facts($caption, $facts));
            }
        }

        return $sections;
    }

    /**
     * The load, and the moment; nothing else here counts.
     *
     * @return MachineCounters
     */
    public function counters(): MachineCounters
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        return new MachineCounters(
            load: $load === false ? 0 : (int) round($load[0] * 100),
            time: (int) (microtime(true) * 1000),
        );
    }

    /**
     * None: PHP has no portable way to ask.
     *
     * @return Collection<MachineProcess>
     */
    public function processes(): Collection
    {
        return new Collection(MachineProcess::class);
    }

    /**
     * The operating system's name and release — `Linux 6.1.0`.
     *
     * @return string
     */
    public static function system(): string
    {
        return UnameMode::System->asked() . ' ' . UnameMode::Release->asked();
    }

    /**
     * Who this process runs as — the web server's user, usually, whose rights are all the service has.
     *
     * @return string
     */
    public static function user(): string
    {
        return function_exists('posix_geteuid') ? MachineEntry::owner(posix_geteuid()) : get_current_user();
    }

    /**
     * The load averages over one, five and fifteen minutes, or nothing where the machine keeps none.
     *
     * @return string
     */
    public static function load(): string
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        return $load === false ? '' : sprintf('%.2F %.2F %.2F', ...$load);
    }

    /**
     * How full the disk $path is on is, or nothing where the machine will not say.
     *
     * @param string $path
     * @return string
     */
    public static function disk(string $path): string
    {
        $total = Diagnostics::muted(static fn(): float|false => disk_total_space($path));
        $free  = Diagnostics::muted(static fn(): float|false => disk_free_space($path));

        return $total === false || $free === false || $total <= 0
            ? ''
            : Measure::share((int) ($total - $free), (int) $total);
    }

    /**
     * The addresses of the interface $name, each with its prefix — or nothing where it has none.
     *
     * @param string $name
     * @return string
     */
    public static function addresses(string $name): string
    {
        $interfaces = function_exists('net_get_interfaces') ? net_get_interfaces() : false;
        $unicast    = $interfaces === false ? [] : ($interfaces[$name]['unicast'] ?? []);
        $addresses  = [];

        foreach ($unicast as $address) {
            $spelled = $address['address'] ?? null;

            // An address is asked whether it is one, rather than its family compared with AF_INET,
            // which only ext/sockets defines — and a link-layer entry has no address to ask about.
            if (is_string($spelled) && filter_var($spelled, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = $spelled;
            }
        }

        return implode(', ', $addresses);
    }

    /**
     * Every interface but loopback, and its addresses.
     *
     * @return Collection<HealthFact>
     */
    private static function network(): Collection
    {
        $interfaces = function_exists('net_get_interfaces') ? net_get_interfaces() : false;
        $facts      = new Collection(HealthFact::class);

        foreach ($interfaces === false ? [] : $interfaces as $name => $interface) {
            $addresses = self::addresses((string) $name);

            if ($name !== self::LOOPBACK && $addresses !== '') {
                $facts = $facts->with(new HealthFact((string) $name, $addresses));
            }
        }

        return $facts;
    }
}
