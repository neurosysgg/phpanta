<?php

declare(strict_types=1);

namespace Phpanta\Http\Api;

use Phpanta\Exception\ApiException;
use Phpanta\Http\HttpMethod;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Machine\MachineConfig;
use Phpanta\Model\Machine\MachineManifest;
use Phpanta\Service\Api\MachineBytes;
use Phpanta\Service\Api\MachineDelete;
use Phpanta\Service\Api\MachineFiles;
use Phpanta\Service\Api\MachineFolder;
use Phpanta\Service\Api\MachineProcesses;
use Phpanta\Service\Api\MachineRename;
use Phpanta\Service\Api\MachineRun;
use Phpanta\Service\Api\MachineSystem;
use Phpanta\Service\Api\MachineUpload;
use Phpanta\Service\Machine\HostProbe;
use Phpanta\Support\AdminPath;
use Phpanta\Support\Collection;
use Phpanta\Text\AdminText;
use Phpanta\Text\Translatable;

/**
 * The MachineAction enum. What the `machine` service can be asked to do: say what the machine is and
 * what it runs, show its files, and — where `data/machine.json` lets it — keep, make, rename and
 * remove them, and run a command.
 *
 * **What a deployment offers is what its file says.** {@link self::offered()} is every read where the
 * service is on, the writes only where it may write, and `run` only where it may run commands too;
 * {@link ApiService::actions()} asks it, so an action switched off is one the admin neither lists nor
 * answers. With no file, nothing is offered and the service is not there at all.
 *
 * **The path is the address.** Every action but `system` and `processes` acts on a place, named after
 * it — `files/home/me`, `raw/home/me/a.flac` — so a signature and a passkey's tap cover it with the
 * rest of the address. A write's form is `GET` of its address; see
 * {@link \Phpanta\View\ApiActionFormView}.
 */
enum MachineAction: string implements ApiAction
{
    /** The machine at a glance, and its live readings. */
    case System = 'system';

    /** The processes holding the most memory. */
    case Processes = 'processes';

    /** A directory's entries, or what a file holds. */
    case Files = 'files';

    /** A file's bytes, shown where a browser can show them. */
    case Raw = 'raw';

    /** A file's bytes, to save. */
    case Download = 'download';

    /** Files kept in a directory. */
    case Upload = 'upload';

    /** A directory made in a directory. */
    case Folder = 'folder';

    /** An entry renamed where it is. */
    case Rename = 'rename';

    /** A file, a link, or an empty directory, removed. */
    case Delete = 'delete';

    /** A command run in a directory. */
    case Run = 'run';

    /**
     * What a deployment whose file says $config offers — nothing where it has none.
     *
     * @param MachineConfig|null $config
     * @return Collection<self>
     */
    public static function offered(?MachineConfig $config): Collection
    {
        return new Collection(self::class)
            ->with(...self::cases())
            ->where(static fn(self $action): bool => $config !== null
                && (!$action->writes() || $config->writes)
                && ($action !== self::Run || $config->commands))
            ->settled();
    }

    /**
     * Where this action is, at the place $subject names — or with no place at all.
     *
     * @param string|null $subject
     * @return string
     */
    public function href(?string $subject): string
    {
        $service = ApiService::Machine->value;
        $version = ApiVersion::V1->value;

        return $subject === null
            ? AdminPath::Action->to($service, $version, $this->value)
            : AdminPath::Subject->to($service, $version, $this->value, $subject);
    }

    /**
     * Whether this action changes the machine, unless it is a dry run.
     *
     * @return bool
     */
    public function writes(): bool
    {
        return match ($this) {
            self::Upload, self::Folder, self::Rename, self::Delete, self::Run => true,
            default                                                            => false,
        };
    }

    /**
     * @return HttpMethod
     */
    public function method(): HttpMethod
    {
        return $this->writes() ? HttpMethod::Post : HttpMethod::Get;
    }

    /**
     * @return Translatable
     */
    public function describe(): Translatable
    {
        return match ($this) {
            self::System    => AdminText::MachineSystem,
            self::Processes => AdminText::MachineProcesses,
            self::Files     => AdminText::MachineFiles,
            self::Raw       => AdminText::MachineRaw,
            self::Download  => AdminText::MachineDownload,
            self::Upload    => AdminText::MachineUpload,
            self::Folder    => AdminText::MachineFolder,
            self::Rename    => AdminText::MachineRename,
            self::Delete    => AdminText::MachineDelete,
            self::Run       => AdminText::MachineRun,
        };
    }

    /**
     * Every write takes `apply`; keeping files takes the files, making and renaming a name, and running
     * a command line.
     *
     * @return Collection<ActionField>
     */
    public function fields(): Collection
    {
        return new Collection(ActionField::class)->with(...match ($this) {
            self::Upload                => [ActionField::Files, ActionField::Apply],
            self::Folder, self::Rename  => [ActionField::Target, ActionField::Apply],
            self::Delete                => [ActionField::Apply],
            self::Run                   => [ActionField::Command, ActionField::Apply],
            default                     => [],
        });
    }

    /**
     * Every action: a browser that unlocked the admin may do all of it, a write with a tap.
     *
     * @return bool
     */
    public function fromBrowser(): bool
    {
        return true;
    }

    /**
     * Every action but the two that are of the machine as a whole.
     *
     * @return bool
     */
    public function takesPath(): bool
    {
        return $this !== self::System && $this !== self::Processes;
    }

    /**
     * @param VerifiedRequest $verified
     * @param string|null     $path     The place, as the address named it.
     * @return ApiHandler
     * @throws ApiException if the service is switched off since the action was resolved, or a write's
     *                      manifest does not carry what it takes.
     */
    public function handler(VerifiedRequest $verified, ?string $path = null): ApiHandler
    {
        $config = MachineConfig::current() ?? throw new ApiException(
            'the machine service is off here: data/machine.json is not there, or does not read',
        );

        return match ($this) {
            self::System    => new MachineSystem(HostProbe::for($config)),
            self::Processes => new MachineProcesses(HostProbe::for($config)),
            self::Files     => new MachineFiles($config, $path),
            self::Raw       => new MachineBytes($config, $path, false),
            self::Download  => new MachineBytes($config, $path, true),
            self::Upload    => new MachineUpload(
                $config,
                $path,
                MachineManifest::parse($verified->manifest, $this),
                $verified->uploads,
            ),
            self::Folder    => new MachineFolder($config, $path, MachineManifest::parse($verified->manifest, $this)),
            self::Rename    => new MachineRename($config, $path, MachineManifest::parse($verified->manifest, $this)),
            self::Delete    => new MachineDelete($config, $path, MachineManifest::parse($verified->manifest, $this)),
            self::Run       => new MachineRun($config, $path, MachineManifest::parse($verified->manifest, $this)),
        };
    }
}
