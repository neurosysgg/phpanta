<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Http\Api\UpdateAction;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Tool\Api\PrivateKey;
use Phpanta\Tool\Api\SignedRequest;
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
use Phpanta\Tool\Update\FrameworkCheckout;
use Phpanta\Tool\Update\PackedFile;
use Phpanta\Tool\Update\TarWriter;

/**
 * The PushUpdate command. Deploys the framework, `src/`, `autoload.php` and `public/` in one signed
 * HTTPS request.
 *
 * **Why it exists is a measurement.** `deploy.sh` rsyncs over a GVFS SFTP mount where a single
 * `stat` costs 480 ms and walking `src/` alone costs 3.7 s; with `-c` it reads every one of 269
 * files on both sides. The same trees are 250 KB gzipped. So this is minutes against under a second,
 * and the difference is entirely round trips rather than bytes.
 *
 * **It does not replace `deploy.sh` and must not be made to.** That script still owns `data/` — 8.6
 * MB of demo audio, rsynced deliberately *without* `--delete` because `demos.php` and `demos/` are
 * gitignored and a mirror from a clone that has never staged a demo would take every demo off the
 * server. It is also the recovery path: a push that breaks `src/` breaks the endpoint that would fix
 * it, and the way back is the mount.
 *
 * It ships the **prod** tree, the same one `deploy.sh` does — `build/dist/public/` rather than
 * `public/` — so what lands is bundled and minified with no source maps, and the manifest goes with
 * it. Building is the caller's job: `npm run build:prod` first, exactly as the script does.
 *
 * The framework ships out of the working tree too, so **it refuses a framework no commit of the site
 * reproduces** — one missing, edited and not committed, or not the one the site records — unless
 * `--any-framework` says that is the point. See {@link FrameworkCheckout}.
 */
final readonly class PushUpdate implements Command
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Directory      $root      The project being pushed: the directory holding `autoload.php`,
     *                                   `src/`, `phpanta/` and `build/dist/`. Given by the site's own
     *                                   entry script, never worked out from where this file happens to sit.
     * @param string         $origin    Which deployment a push goes to unless `--url` says otherwise — an
     *                                   origin, not an endpoint; the path is {@link SignedRequest}'s to compute.
     * @param string         $keyPath   Where the private key is unless `--key` says otherwise, relative to
     *                                   `$HOME`.
     * @param Transport|null $transport A test seam; production sends over curl.
     */
    public function __construct(
        private Directory  $root,
        private string     $origin,
        private string     $keyPath,
        private ?Transport $transport = null,
    ) {}

    /**
     * @return string
     */
    public function name(): string
    {
        return 'push-update';
    }

    /**
     * @return string
     */
    public function usage(): string
    {
        return '[--dry-run] [--no-mirror] [--any-framework] [--url <origin>] [--key <file>]';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Deploy phpanta/, src/, autoload.php and public/ in one signed request.';
    }

    /**
     * @return list<Option>
     */
    public function options(): array
    {
        return PushUpdateOption::cases();
    }

    /**
     * @param Input $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        $dist = $this->root->directory('build')->directory('dist');

        if (!$dist->directory('public')->exists()) {
            $output->error("build/dist/ is not there — run `npm run build:prod` first.\n");
            return ExitCode::Failure;
        }

        // Before anything is signed or sent, and for a dry run too: a dry run that passed a framework
        // the real push would refuse would be a plan for a different push.
        if (!$input->has(PushUpdateOption::AnyFramework)) {
            $refusal = new FrameworkCheckout($this->root)->refusal();

            if ($refusal !== null) {
                $output->error(sprintf(
                    "%s: %s\n  (--any-framework ships it as it stands.)\n",
                    $this->name(),
                    $refusal,
                ));

                return ExitCode::Failure;
            }
        }

        $dryRun = $input->has(PushUpdateOption::DryRun);
        $files  = $this->files($dist);
        $base   = $input->value(PushUpdateOption::Url) ?? $this->origin;

        // A missing or unusable key is an ordinary mistake and reads as one. Runner only catches a
        // UsageException around argument parsing — by design, since a command's run() answers with
        // an ExitCode — so it is caught here rather than escaping as a stack trace.
        try {
            $archive = $this->archive($files);
            $request = SignedRequest::build(
                new Url($base),
                ApiService::Update,
                ApiVersion::V1,
                UpdateAction::Patch,
                $archive,
                // The flags are negative and the manifest's fields are positive, which is the one
                // inversion in this command: `--dry-run` means `apply: false`.
                ['apply' => !$dryRun, 'mirror' => !$input->has(PushUpdateOption::NoMirror)],
                PrivateKey::fromFile($this->key($input)),
            );
        } catch (UsageException $exception) {
            $output->error($this->name() . ': ' . $exception->getMessage() . "\n");

            return ExitCode::Usage;
        }

        $output->error(sprintf(
            "%s %d files, %s archive → %s\n",
            $dryRun ? 'dry run:' : 'pushing',
            $files->count(),
            $this->humanised(strlen($archive)),
            $request->url->render(),
        ));

        try {
            $response = ($this->transport ?? new CurlTransport())->send($request);
        } catch (TransportException $exception) {
            $output->error($this->name() . ': ' . $exception->getMessage() . "\n");

            return ExitCode::Failure;
        }

        $output->out($response->body);

        if (!$response->isOk()) {
            // 404 and 405 are the same answer wearing two faces: /api replies exactly as the site
            // replies for an address that does not exist, which is a 404 for a read method and a
            // 405 for a write one — and a push is a POST. Either means the request was not
            // verified, and the endpoint deliberately will not say which check failed.
            $unverified = $response->status === 404 || $response->status === 405;

            $output->error(sprintf(
                "\nrefused with %d.%s\n",
                $response->status,
                $unverified
                    ? "\n  That is what /api answers to anything it will not verify — it does not"
                    . " say which check failed, by design. In order of likelihood:\n"
                    . "    1. data/update.pub on the server does not match this private key\n"
                    . "    2. this machine's clock is more than five minutes from the server's\n"
                    . "    3. this exact payload was already applied (rebuild to mint a new serial)\n"
                    . "    4. the server is older than /api (./deploy.sh is the way to update it)"
                    : '',
            ));

            return ExitCode::Failure;
        }

        return ExitCode::Success;
    }

    /**
     * Everything a push carries, named as the server expects — in the order it is written.
     *
     * **The server writes a payload in the order it is packed**, so the order is the dependency
     * order, and two dependencies decide it:
     *
     * - `public/index.php` is the one file every request runs, so it changes only once everything
     *   it will load is already there: the framework, then the site's source, then the autoloader
     *   that finds both. Until then the old `index.php` runs against the new classes — which works,
     *   because the mirror deletes nothing until every file is written.
     * - The prod `AssetManifest.php` names stamped asset URLs, so it lands only once the bytes those
     *   URLs name are up — after the webroot, last of all. Before that, a visitor could cache the
     *   old bytes under the new stamp. The working tree's copy, written with the rest of `src/`,
     *   carries the debug stamp, which nothing links to once the push is done.
     *
     * The framework is only packed where there is one — `phpanta/src/` and `phpanta/autoload.php`,
     * and nothing else of the repository it comes from.
     *
     * @param Directory $dist
     * @return Collection<PackedFile>
     */
    private function files(Directory $dist): Collection
    {
        $repository = $this->root;
        $framework  = $repository->directory('phpanta');
        $files      = new Collection(PackedFile::class);

        if ($framework->file('autoload.php')->exists()) {
            $files = $files
                ->with(...TarWriter::tree($framework->directory('src'), 'phpanta/src')->toValues())
                ->with(new PackedFile('phpanta/autoload.php', (string) $framework->file('autoload.php')->read()));
        }

        // src/ comes from build/dist where it differs and from the working tree otherwise, which is
        // the one file deploy.sh also overlays: AssetManifest.php is stamped for the minified bytes
        // rather than the readable ones. Taking dist's copy last is what makes it win.
        return $files
            ->with(...TarWriter::tree($repository->directory('src'), 'src')->toValues())
            ->with(new PackedFile('autoload.php', (string) $repository->file('autoload.php')->read()))
            ->with(...TarWriter::tree($dist->directory('public'), 'public')->toValues())
            ->with(...TarWriter::tree($dist->directory('src'), 'src')->toValues());
    }

    /**
     * The payload: every file, tarred and gzipped.
     *
     * The body of the request and nothing else — where its predecessor framed a manifest and a
     * signature in front of these bytes, both of those now ride in the `Authorization` header and
     * the body is exactly what it claims to be. That is what lets a read be signed the same way
     * with no body at all.
     *
     * @param Collection<PackedFile> $files
     * @return string
     *
     * @throws UsageException if the archive cannot be compressed.
     */
    private function archive(Collection $files): string
    {
        $archive = gzencode(TarWriter::pack($files), 9);

        if ($archive === false) {
            throw new UsageException('could not gzip the archive');
        }

        return $archive;
    }

    /**
     * The private key, from `--key` or from the default under `$HOME`.
     *
     * @param Input $input
     * @return File
     *
     * @throws UsageException if `--key` was not given and `$HOME` is not set.
     */
    private function key(Input $input): File
    {
        $given = $input->value(PushUpdateOption::Key);
        if ($given !== null) {
            return new File($given);
        }

        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            throw new UsageException('HOME is not set, so --key must name the private key.');
        }

        return new File($home . '/' . $this->keyPath);
    }

    /**
     * A byte count a person can read.
     *
     * @param int $bytes
     * @return string
     */
    private function humanised(int $bytes): string
    {
        return $bytes < 1024 ? $bytes . ' B' : sprintf('%.1f KB', $bytes / 1024);
    }
}
