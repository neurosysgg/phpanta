<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Phpanta\Http\Api\ApiService;
use Phpanta\Http\Api\ApiVersion;
use Phpanta\Http\Api\UpdateAction;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Tool\Api\ApiTarget;
use Phpanta\Tool\Api\PrivateKey;
use Phpanta\Tool\Api\ResultReader;
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
use Phpanta\Tool\Update\FrameworkCheckout;
use Phpanta\Tool\Update\PackedFile;
use Phpanta\Tool\Update\TarWriter;

/**
 * The PushUpdate command. Deploys the framework, `src/`, `autoload.php` and `public/` in one signed
 * HTTPS request.
 *
 * **Why it exists is a measurement.** A full deploy over an SFTP mount pays a round trip per
 * `stat`, and walking a source tree that way costs seconds before a byte has moved. The same trees
 * gzipped are a few hundred kilobytes. So this is minutes against under a second, and the
 * difference is entirely round trips rather than bytes.
 *
 * **It does not replace the full deploy and must not be made to.** That one still owns `data/`,
 * which a mirror from a clone that never staged the gitignored half of it would empty. It is also
 * the recovery path: a push that breaks `src/` breaks the endpoint that would fix it.
 *
 * It ships the **prod** tree — `build/dist/public/` rather than `public/` — so what lands is bundled
 * and minified with no source maps, and the stamped manifest goes with it. Building is the caller's
 * job: `tools/build-prod.mjs` first.
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
     * @param string         $keyPath   That deployment's private key, relative to `$HOME`. Any other
     *                                   origin signs with its own; see {@link ApiTarget}.
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
     * None: every word on this command line is a flag, and a word that is not one is a mistake —
     * `-n` for `--dry-run` was a real push before it was refused.
     *
     * @return Arity
     */
    public function operands(): Arity
    {
        return Arity::none();
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
            $output->error("build/dist/ is not there — build the prod tree with tools/build-prod.mjs first.\n");
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

        // A missing or unusable key, a tree with nothing in it, an origin that is not one — each an
        // ordinary mistake that reads as one. Runner only catches a UsageException around argument
        // parsing — by design, since a command's run() answers with an ExitCode — so it is caught here
        // rather than escaping as a stack trace.
        try {
            $files   = $this->files($dist);
            $target  = ApiTarget::resolve(
                $input->value(PushUpdateOption::Url),
                $input->value(PushUpdateOption::Key),
                $this->origin,
                $this->keyPath,
                ApiTarget::home(),
            );
            $archive = $this->archive($files);
            $request = SignedRequest::build(
                $target->origin,
                ApiService::Update,
                ApiVersion::V1,
                UpdateAction::Patch,
                $archive,
                // The flags are negative and the manifest's fields are positive, which is the one
                // inversion in this command: `--dry-run` means `apply: false`.
                ['apply' => !$dryRun, 'mirror' => !$input->has(PushUpdateOption::NoMirror)],
                PrivateKey::fromFile($target->key),
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

        // The report's own text where the answer is a result. An answer that is not the admin's at
        // all — a site's own page — says nothing worth printing, and the lines below say what it
        // means instead.
        $text = ResultReader::read($response->body)?->text();

        if ($text !== null || $response->isOk()) {
            $output->out($text ?? $response->body);
        }

        if (!$response->isOk()) {
            $output->error(sprintf(
                "\nrefused with %d.%s\n",
                $response->status,
                match (true) {
                    // A request the gate will not verify is one answer, which deliberately does not
                    // say which check failed.
                    $response->status === 401 => "\n  That is what the admin answers a request it cannot"
                        . " verify — it does not say which check failed, by design. In order of likelihood:\n"
                        . "    1. data/update.pub on the server does not match this private key\n"
                        . "    2. this machine's clock is more than five minutes from the server's\n"
                        . "    3. another call was signed in the same second — a serial is the time, so wait"
                        . " one and push again",
                    $text === null => "\n  That is not the way the admin answers, so the server has no /admin:"
                        . " it is older than this command, and a full deploy (./deploy.sh) updates it",
                    default => '',
                },
            ));

            return ExitCode::Failure;
        }

        return ExitCode::Success;
    }

    /**
     * Everything a push carries, named as the server expects — in the order it is written, and each
     * name once.
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
     *   old bytes under the new stamp.
     *
     * **Each name once, and that is what makes the second rule true.** `build/dist/src/` holds the
     * files that differ from the working tree's — the manifest, stamped for the minified bytes — and
     * a name packed from both trees reaches the server twice. The server keeps a repeated name's
     * *first* position and last contents, so the stamped manifest would land with `src/`, before
     * the webroot it names. The working tree's copy of anything dist replaces is therefore left out.
     *
     * The framework is only packed where there is one — `phpanta/src/` and `phpanta/autoload.php`,
     * and nothing else of the repository it comes from.
     *
     * @param Directory $dist
     * @return Collection<PackedFile>
     *
     * @throws UsageException if a tree that must carry files carries none, or a file cannot be read.
     */
    private function files(Directory $dist): Collection
    {
        $repository = $this->root;
        $framework  = $repository->directory('phpanta');
        $files      = new Collection(PackedFile::class);

        if ($framework->file('autoload.php')->exists()) {
            $files = $files
                ->with(...self::tree($framework->directory('src'), 'phpanta/src')->toValues())
                ->with(new PackedFile('phpanta/autoload.php', (string) $framework->file('autoload.php')->read()));
        }

        $stamped  = TarWriter::tree($dist->directory('src'), 'src');
        $replaced = [];

        foreach ($stamped as $file) {
            $replaced[$file->name] = true;
        }

        return $files
            ->with(...self::tree($repository->directory('src'), 'src')
                ->where(static fn(PackedFile $file): bool => !isset($replaced[$file->name]))
                ->toValues())
            ->with(new PackedFile('autoload.php', (string) $repository->file('autoload.php')->read()))
            ->with(...self::tree($dist->directory('public'), 'public')->toValues())
            ->with(...$stamped->toValues());
    }

    /**
     * {@link TarWriter::tree()}, refusing a tree that packs nothing.
     *
     * An empty tree is never what somebody meant to push — a build that wrote nowhere, a path that
     * resolves somewhere else — and a server that mirrors reads a tree it was sent as the whole of
     * that tree.
     *
     * @param Directory $directory
     * @param string $prefix
     * @return Collection<PackedFile>
     *
     * @throws UsageException
     */
    private static function tree(Directory $directory, string $prefix): Collection
    {
        $files = TarWriter::tree($directory, $prefix);

        if ($files->isEmpty()) {
            throw new UsageException(sprintf(
                '%s/ packs no files (%s) — refusing to push a tree with nothing in it',
                $prefix,
                $directory->path,
            ));
        }

        return $files;
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
