<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use InvalidArgumentException;
use Phpanta\Http\Api\AccessAction;
use Phpanta\Http\Api\ActionField;
use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Http\Origin;
use Phpanta\Model\Update\UpdateManifest;
use Phpanta\Support\File;
use Phpanta\Tool\Api\ApiTarget;
use Phpanta\Tool\Api\PrivateKey;
use Phpanta\Tool\Api\SignedRequest;
use Phpanta\Tool\Cli\Arity;
use Phpanta\Tool\Cli\Command;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Input;
use Phpanta\Tool\Cli\Option;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\UsageException;
use Phpanta\Tool\Http\CurlTransport;
use Phpanta\Tool\Http\Transport;
use Phpanta\Tool\Http\TransportException;
use Phpanta\Tool\Http\Url;
use Phpanta\Tool\Passkey\SoftwareDevice;

/**
 * The Authenticator class. The `authenticator` command: plays a passkey device against a local copy
 * of the admin, over real HTTP, and checks that what must happen once happens once.
 *
 * A browser that cannot make a passkey — one on a machine with no platform authenticator — leaves
 * the admin's browser side untried by hand. This is that browser and that authenticator at once: it
 * keeps the session cookie, reads each page's form token and challenge, and answers with a
 * {@link SoftwareDevice}. The first run registers the device and enrols it with the signing key; every
 * run then walks what the admin promises:
 *
 * 1. an unlock opens the admin, and a listing below it answers;
 * 2. the same unlock sent again — the session that carried its challenge copied — opens nothing;
 * 3. a write with its tap reaches the action, and the same post sent again is a `409`, stale;
 * 4. locking the admin ends every copy of the session.
 *
 * The write revokes a credential id nobody holds: it spends a serial like any write, which is the
 * half under test, and changes nothing.
 *
 * **A `*.localhost` origin, and nothing else.** The device is a key in a file, and an enrolled one
 * opens the admin it is enrolled at; on a deployment anybody else can reach, that would be a
 * credential left lying on a laptop. A local copy trusts a request's own `Origin` in development,
 * from loopback only, which is what lets a ceremony run at the address it is served on.
 *
 * Each post to the entrance counts against its throttle — ten in fifteen minutes per address — and
 * a run makes four, or five the first time.
 */
final readonly class Authenticator implements Command
{
    /** What the device is enrolled as, unless `--name` says otherwise. */
    private const string NAME = 'software authenticator';

    /** Where devices are kept, under `$HOME`. */
    private const string DEVICES = '/.config/phpanta';

    /** The listing a verified browser reads. */
    private const string LISTING = '/admin/access/v1/passkeys';

    /** The write the walk makes: a revocation that finds nothing to revoke. */
    private const string WRITE = '/admin/access/v1/revoke';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string         $origin    The deployment the signing key belongs to by default.
     * @param string         $keyPath   That deployment's key, relative to `$HOME`.
     * @param Transport|null $transport How requests go out; curl unless a test says otherwise.
     */
    public function __construct(
        private string     $origin,
        private string     $keyPath,
        private ?Transport $transport = null,
    ) {}

    /**
     * @return string
     */
    public function name(): string
    {
        return 'authenticator';
    }

    /**
     * @return string
     */
    public function usage(): string
    {
        return '--url <https://….localhost> [--device <file>] [--name <name>] [--key <file>]';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Play a passkey device against a local copy of the admin, and check what must happen once does.';
    }

    /**
     * @return list<Option>
     */
    public function options(): array
    {
        return AuthenticatorOption::cases();
    }

    /**
     * @return Arity
     */
    public function operands(): Arity
    {
        return Arity::none();
    }

    /**
     * @param Input  $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        try {
            $url    = $this->local($input->value(AuthenticatorOption::Url));
            $file   = self::deviceFile($input->value(AuthenticatorOption::Device), $url);
            $device = SoftwareDevice::fromFile($file);
            $walk   = new AuthenticatorWalk(
                $this->transport ?? new CurlTransport(),
                $url,
                Origin::of($url->render()),
                $output,
            );

            if ($device === null) {
                $device = SoftwareDevice::mint();
                $kept   = $this->enrol($walk, $device, $input, $url)
                    && $walk->check($device->keepIn($file), 'the device is kept in ' . $file->path);

                if (!$kept) {
                    return ExitCode::Failure;
                }

                // A browser's write takes the second its challenge was minted as its serial, and the
                // enrolment has just spent this one: a write in the same second would be stale.
                time_sleep_until(time() + 1);
            } else {
                $output->out(sprintf(
                    "  --    %s holds a device already, so it is not registered again\n",
                    $file->path,
                ));
            }

            return $walk->promises($device, self::LISTING, self::WRITE) ? ExitCode::Success : ExitCode::Failure;
        } catch (UsageException $refusal) {
            $output->error($this->name() . ': ' . $refusal->getMessage() . "\n");

            return ExitCode::Usage;
        } catch (TransportException $failure) {
            $output->error($this->name() . ': ' . $failure->getMessage() . "\n");

            return ExitCode::Failure;
        }
    }

    /**
     * Registers $device at the entrance and enrols the code it is handed with the signing key.
     *
     * @param AuthenticatorWalk $walk
     * @param SoftwareDevice    $device
     * @param Input             $input
     * @param Url               $url
     * @return bool
     * @throws UsageException if the signing key cannot be located.
     * @throws TransportException
     */
    private function enrol(AuthenticatorWalk $walk, SoftwareDevice $device, Input $input, Url $url): bool
    {
        $code = $walk->register($device);

        if ($code === null) {
            return false;
        }

        $target = ApiTarget::resolve(
            $url->render(),
            $input->value(AuthenticatorOption::Key),
            $this->origin,
            $this->keyPath,
            ApiTarget::home(),
        );

        $enrolled = $walk->send(SignedRequest::build(
            $target->origin,
            ApiService::Access,
            ApiVersion::V1,
            AccessAction::Enrol,
            '',
            [
                ActionField::Code->value => $code,
                ActionField::Name->value => $input->value(AuthenticatorOption::Name) ?? self::NAME,
                UpdateManifest::APPLY    => true,
            ],
            PrivateKey::fromFile($target->key),
        ));

        return $walk->check($enrolled->isOk(), 'access v1 enrol adds it, signed with ' . $target->key->path, $enrolled);
    }

    /**
     * $url as an origin, refused unless it is a local one.
     *
     * @param string|null $url
     * @return Url
     * @throws UsageException
     */
    private function local(?string $url): Url
    {
        try {
            $origin = Url::origin(
                $url ?? throw new UsageException('it needs --url, the local copy to play a device against.'),
            );
        } catch (InvalidArgumentException $invalid) {
            throw new UsageException($invalid->getMessage());
        }

        $host = Origin::of($origin->render())->host();

        if ($host !== 'localhost' && !str_ends_with($host, '.localhost')) {
            throw new UsageException(sprintf(
                '%s is not a local copy. The device is a key in a file, and an enrolled one opens the admin it is '
                . 'enrolled at, so it plays against a *.localhost origin and nothing else.',
                $origin->render(),
            ));
        }

        return $origin;
    }

    /**
     * The file the device for $url is kept in.
     *
     * @param string|null $given
     * @param Url         $url
     * @return File
     * @throws UsageException if no file is given and there is no `$HOME` to keep one under.
     */
    private static function deviceFile(?string $given, Url $url): File
    {
        if ($given !== null) {
            return new File($given);
        }

        $home = ApiTarget::home()
            ?? throw new UsageException('$HOME is not set, so name the device file with --device.');

        return new File($home . self::DEVICES . '/device-' . str_replace(':', '-', $url->authority()) . '.json');
    }
}
