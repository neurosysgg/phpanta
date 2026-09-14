<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Phpanta\App;
use Phpanta\Controller\Layered;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\ViewResponse;
use Phpanta\Support\Directory;
use Phpanta\Tool\Cli\Arity;
use Phpanta\Tool\Cli\Command;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Input;
use Phpanta\Tool\Cli\Option;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\UsageException;
use Phpanta\Tool\Export\Anchors;
use Phpanta\Tool\Export\BasePath;
use ReflectionClass;

/**
 * The Export command. Renders an app's pages, and the assets they load, into a static site.
 *
 * **Every page is the one the running site would send**: the route's own controller answers a
 * {@link Request::synthetic()} request inside every layer the router would have put around it —
 * the app's, then the route's — and {@link ViewResponse::render()} writes the body
 * {@link ViewResponse::answer()} would have — so there is no second renderer to drift. Which routes
 * are pages is the route's to say, see {@link \Phpanta\Support\Route::exportedPaths()}; the rest —
 * the API, a download's redirect, anything behind a password — has no business on a static host,
 * and a route that claims to be a page and answers with something else fails the export by name —
 * a route behind a password among them, which answers the export's anonymous request with its 401.
 *
 * What it writes, for a host that can only serve files:
 *
 * - `index.html` for `/`, `x.html` for `/x` and `a/b.html` for `/a/b` — the name a static host
 *   finds for an address with no extension, under the path **decoded**, since that is what a host
 *   looks for — and `404.html`, the app's own not-found page, which is the name GitHub Pages serves
 *   for any address it does not have. In the app's default language — and, for an app whose
 *   languages have addresses of their own ({@link App::languageAddresses()}), once more at each
 *   language's: `x.en.html`, `x.de.html`, `index.de.html`, each in its language and with its links
 *   in that language, while `x.html` stays the default language's way in.
 * - The webroot's files, less what only a PHP host reads (`*.php`, `.htaccess`, `.user.ini`).
 * - **The stamped asset directories, as directories.** A page names `/assets/js/v-a1b2c3d4/main.js`,
 *   and on the site a rewrite strips the stamp; a static host has no rewrite, so the tree is
 *   written under the stamp and the URLs in the manifest resolve exactly as they are.
 * - `.nojekyll`, or GitHub Pages hides every file whose name starts with an underscore, and
 *   {@link self::MARKER}, which is how a later export knows the directory is one it may empty.
 *
 * Then {@link BasePath} moves every address under `--base`, and **the export fails** on any
 * root-absolute address that still lacks the base, on any that names a file it did not write, and on
 * any link to an anchor its page does not have ({@link Anchors}) — a broken link found here rather
 * than by a visitor.
 */
final readonly class Export implements Command
{
    /** The stamp segment `public/.htaccess` and the dev router strip; see build-assets.mjs. */
    private const string STAMPED = '#/assets/(js|css)/v-([0-9a-f]{8})/#';

    /** What only a PHP host reads, and so nothing a static one should be handed. */
    private const string SERVER_ONLY = '#(\.php|^\.htaccess|^\.user\.ini)\z#';

    /**
     * The file that says a directory holds an export, and so may be emptied by the next one.
     *
     * A name only this command writes. `.nojekyll` used to do the job and cannot: any GitHub Pages
     * directory may carry one, including one somebody made by hand.
     */
    private const string MARKER = '.phpanta-export';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param App $app The booted app, whose routes, shell and webroot are exported.
     */
    public function __construct(private App $app) {}

    /**
     * @return string
     */
    public function name(): string
    {
        return 'export';
    }

    /**
     * @return string
     */
    public function usage(): string
    {
        return '--out <dir> [--base <path>] [--debug]';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Render every exported page, and the assets they load, into a static site.';
    }

    /**
     * @return list<Option>
     */
    public function options(): array
    {
        return ExportOption::cases();
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
            $out  = $this->out($input);
            $base = new BasePath(
                $input->value(ExportOption::Base) ?? '/',
                BasePath::urlAttributesOf($this->app->vocabulary()),
            );
        } catch (UsageException $exception) {
            $output->error($this->name() . ': ' . $exception->getMessage() . "\n");

            return ExitCode::Usage;
        }

        $failure = $this->export($out, $base, $input->has(ExportOption::Debug), $output);

        if ($failure !== null) {
            $output->error($this->name() . ': ' . $failure . "\n");

            return ExitCode::Failure;
        }

        return ExitCode::Success;
    }

    /**
     * Writes the export, or answers why it could not.
     *
     * @param Directory $out
     * @param BasePath  $base
     * @param bool      $debug
     * @param Output    $output
     * @return string|null
     */
    private function export(Directory $out, BasePath $base, bool $debug, Output $output): ?string
    {
        $public = $debug
            ? $this->app->above()->directory('public')
            : $this->app->above()->directory('build')->directory('dist')->directory('public');

        if (!$public->exists()) {
            return $debug
                ? 'public/ is not there.'
                : 'build/dist/ is not there — build the prod tree with tools/build-prod.mjs first, or export '
                    . 'the debug tree with --debug.';
        }

        if (!$debug && ($failure = $this->loadProdManifest()) !== null) {
            return $failure;
        }

        if (($failure = self::emptied($out)) !== null) {
            return $failure;
        }

        // Marked before anything else is written, so an export that fails half way is still one the
        // next may empty.
        $out->file(self::MARKER)->write(
            "Written by Phpanta's export, which empties a directory only when this file is in it.\n",
        );

        // ── the pages ──

        $languages = $this->app->languages();
        $language  = $languages->default();
        $pages     = [];

        foreach ($this->app->routeTable() as $route) {
            foreach ($route->exportedPaths() as $path) {
                $params = $route->matches($path);

                if ($params === false) {
                    return sprintf(
                        '%s was given as a page of the route for %s, which does not match it.',
                        $path,
                        $route->path()->value,
                    );
                }

                // The page once at its own address, and once more at each language's where the app
                // gives languages addresses of their own. $path becomes each address in turn, which
                // is the address every sentence below names.
                foreach ($this->app->languageAddresses()->exported($path, $languages) as $address) {
                    $path     = $address->address;
                    $request  = Request::synthetic($path, $address->language);
                    $response = Layered::around(
                        $this->app->layerTable(),
                        Layered::around($route->layers(), $route->createController($params)),
                    )->handle($request);

                    // A route behind a password lands here too — a gate on the route as much as one
                    // in its controller, since the layers above are the router's: the export's
                    // request carries no credential, so its answer is the refusal, and a refusal
                    // written to a file would be served to everyone as the page.
                    if (!$response instanceof ViewResponse || $response->status() !== HttpStatusCode::Ok) {
                        return sprintf(
                            '%s is exported, and answers with a %d (%s) rather than a page with a 200 — a'
                            . ' static host would serve whatever it wrote as one.',
                            $path,
                            $response->answer($request)->status()->value,
                            new ReflectionClass($response)->getShortName(),
                        );
                    }

                    $file = self::pageFile($path);

                    if ($file === null) {
                        return sprintf(
                            '%s has a segment that decodes to nothing, to a dot segment, or to a slash, so no'
                            . ' static host could serve it from a file.',
                            $path,
                        );
                    }

                    if (isset($pages[$file])) {
                        return sprintf('%s and %s would both be written to %s.', $pages[$file][0], $path, $file);
                    }

                    $pages[$file] = [$path, $response->render($request)];
                }
            }
        }

        if (isset($pages['404.html'])) {
            return sprintf(
                '%s would be written to 404.html, which is where the not-found page goes.',
                $pages['404.html'][0],
            );
        }

        $missing = $this->app->notFound(Request::synthetic('/404', $language));

        if (!$missing instanceof ViewResponse) {
            return sprintf(
                'the app answers an address it does not have with %s, not a page, so there is no 404.html to write.',
                $missing::class,
            );
        }

        $pages['404.html'] = ['(not found)', $missing->render(Request::synthetic('/404', $language))];

        // ── the files ──

        $stamps = [];

        foreach ($pages as $file => [, $markup]) {
            preg_match_all(self::STAMPED, $markup, $found, PREG_SET_ORDER);

            foreach ($found as [, $kind, $stamp]) {
                $stamps["$kind/v-$stamp"] = $kind;
            }

            self::write($out, $file, $base->html($markup));
        }

        $copied = self::copy($public, $out, '');

        foreach ($stamps as $stamped => $kind) {
            $copied += self::copy($public->directory('assets')->directory($kind), $out, "assets/$stamped");
        }

        foreach (self::files($out, '') as $file) {
            if (str_ends_with($file, '.css')) {
                $stylesheet = $out->file($file);
                $stylesheet->write($base->css((string) $stylesheet->read()));
            }
        }

        $out->file('.nojekyll')->write('');

        // ── the check ──

        $problems = [];
        $ids      = [];

        foreach (self::files($out, '') as $file) {
            $addresses = match (true) {
                str_ends_with($file, '.html') => $base->addresses((string) $out->file($file)->read()),
                str_ends_with($file, '.css')  => $base->stylesheetAddresses((string) $out->file($file)->read()),
                default                       => null,
            };

            foreach ($addresses ?? [] as $address) {
                if ($base->lacksBase($address)) {
                    $problems[] = "$file names $address, which is not under {$base->path}";
                } elseif (self::resolvedFile($out, $base->withinExport($address)) === null) {
                    $problems[] = "$file links to $address, which the export did not write";
                }
            }

            if (!str_ends_with($file, '.html')) {
                continue;
            }

            foreach (Anchors::fragmentLinks((string) $out->file($file)->read()) as [$address, $fragment]) {
                $page = $address === '' ? $file : self::anchoredPage($out, $base, $address);

                if ($page === null) {
                    continue;
                }

                $ids[$page] ??= Anchors::idsIn((string) $out->file($page)->read());

                if (!Anchors::names($ids[$page], $fragment)) {
                    $problems[] = "$file links to $address#$fragment, and that page has no id \"$fragment\"";
                }
            }
        }

        if ($problems !== []) {
            return "the export is not self-contained:\n  " . implode("\n  ", array_unique($problems));
        }

        $output->error(sprintf(
            "export: %d pages and a 404, %d files, served under %s → %s\n",
            count($pages) - 1,
            $copied,
            $base->path,
            $out->path,
        ));

        return null;
    }

    /**
     * `--out`, resolved against the working directory.
     *
     * @param Input $input
     * @return Directory
     *
     * @throws UsageException if it was not given.
     */
    private function out(Input $input): Directory
    {
        $given = $input->value(ExportOption::Out)
            ?? throw new UsageException('--out is required: the directory the static site is written to.');

        return new Directory(str_starts_with($given, '/') ? $given : (getcwd() ?: '.') . '/' . $given);
    }

    /**
     * Loads `build/dist/`'s manifest in place of the working tree's, so every page names the bundled
     * assets that are being exported rather than the debug tree's.
     *
     * The class is required before anything asks for it, which is what makes the autoloader never
     * look for the working copy — and refused if something already has, since the pages would then
     * name URLs the export does not contain.
     *
     * @return string|null Why it could not be, or null.
     */
    private function loadProdManifest(): ?string
    {
        $manifests = glob($this->app->above()->path . '/build/dist/src/*/AssetManifest.php') ?: [];

        if (count($manifests) !== 1) {
            return sprintf(
                'build/dist/src/ holds %d AssetManifest.php, and the export needs exactly one.',
                count($manifests),
            );
        }

        $source = (string) file_get_contents($manifests[0]);

        if (preg_match('/^namespace\s+([^;\s]+)\s*;/m', $source, $match) !== 1) {
            return $manifests[0] . ' declares no namespace.';
        }

        $class = $match[1] . '\\AssetManifest';

        if (class_exists($class, false)) {
            return "$class is already loaded from the working tree, so the pages would name the debug tree's assets.";
        }

        require_once $manifests[0];

        return null;
    }

    /**
     * Makes $out an empty directory — refusing one that holds anything an export did not write.
     *
     * An export's own output carries {@link self::MARKER}, so a directory with one is emptied;
     * anything else that is not empty is somebody's, and a mistyped `--out .` must not delete a
     * repository. A `.git` is refused whatever else is there — a repository with a marker copied
     * into it is still a repository.
     *
     * @param Directory $out
     * @return string|null
     */
    private static function emptied(Directory $out): ?string
    {
        if (!$out->exists()) {
            return $out->create() ? null : "could not create {$out->path}.";
        }

        $entries = array_diff(scandir($out->path) ?: [], ['.', '..']);

        if ($entries === []) {
            return null;
        }

        // file_exists rather than is_dir: in a worktree or a submodule, .git is a file.
        if (file_exists($out->path . '/.git')) {
            return "{$out->path} holds .git — refusing to empty a repository.";
        }

        if (!$out->file(self::MARKER)->exists()) {
            return sprintf(
                '%s is not empty and is not an earlier export (it has no %s) — refusing to empty it. An'
                . ' export written before the marker existed has only .nojekyll; delete that one by hand.',
                $out->path,
                self::MARKER,
            );
        }

        foreach ($entries as $entry) {
            self::remove($out->path . '/' . $entry);
        }

        return null;
    }

    /**
     * Deletes $path and, for a directory, everything under it — without following a symlink, which
     * is deleted as the link it is.
     *
     * @param string $path
     * @return void
     */
    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
                self::remove($path . '/' . $entry);
            }

            rmdir($path);

            return;
        }

        unlink($path);
    }

    /**
     * The file a page is written to: `index.html` for `/`, and otherwise its path **decoded**, with
     * `.html` on the end — decoded because a static host decodes the address it is asked for before
     * it looks for a file, so `/pages/caf%C3%A9` is served from `pages/café.html`.
     *
     * Null for a path whose segments do not decode into names a file can have: empty, `.` or `..`,
     * or holding a slash or a NUL. Each would put the page somewhere its address does not lead.
     *
     * @param string $path
     * @return string|null
     */
    private static function pageFile(string $path): ?string
    {
        if ($path === '/') {
            return 'index.html';
        }

        $names = [];

        foreach (explode('/', substr($path, 1)) as $segment) {
            $name = rawurldecode($segment);

            if (in_array($name, ['', '.', '..'], true) || strpbrk($name, "/\0") !== false) {
                return null;
            }

            $names[] = $name;
        }

        $file = implode('/', $names);

        // A language's own address already ends in the extension — `rules.de.html` — and is written
        // under its own name, which is the name a static host looks for.
        return str_ends_with($file, '.html') ? $file : $file . '.html';
    }

    /**
     * Writes $markup to $file under $out, making the directories it sits in.
     *
     * @param Directory $out
     * @param string    $file
     * @param string    $markup
     * @return void
     */
    private static function write(Directory $out, string $file, string $markup): void
    {
        $directory = dirname($out->path . '/' . $file);

        if (!is_dir($directory)) {
            new Directory($directory)->create();
        }

        $out->file($file)->write($markup);
    }

    /**
     * Copies every file under $from into $to at $at, less what only a PHP host reads.
     *
     * @param Directory $from
     * @param Directory $to
     * @param string    $at A path under $to, or `''` for $to itself.
     * @return int How many files were copied.
     */
    private static function copy(Directory $from, Directory $to, string $at): int
    {
        $copied = 0;

        foreach (self::files($from, '') as $file) {
            if (preg_match(self::SERVER_ONLY, basename($file)) === 1) {
                continue;
            }

            $target = $at === '' ? $file : "$at/$file";
            self::write($to, $target, (string) $from->file($file)->read());
            $copied++;
        }

        return $copied;
    }

    /**
     * Every file under $in, as a path relative to it, symlinks not followed.
     *
     * @param Directory $in
     * @param string    $prefix
     * @return list<string>
     */
    private static function files(Directory $in, string $prefix): array
    {
        $files = [];
        $here  = $prefix === '' ? $in->path : $in->path . '/' . $prefix;

        foreach (array_diff(scandir($here) ?: [], ['.', '..']) as $entry) {
            $relative = $prefix === '' ? $entry : "$prefix/$entry";

            if (is_link("$here/$entry")) {
                continue;
            }

            if (is_dir("$here/$entry")) {
                array_push($files, ...self::files($in, $relative));
            } else {
                $files[] = $relative;
            }
        }

        return $files;
    }

    /**
     * The file an address under the export names, if the export wrote it — `x` as `x.html` or
     * `x/index.html`, `` as `index.html`, anything with an extension as itself — ignoring a query or a
     * fragment, and decoded, the way the host will decode it. Null for a file it did not write.
     *
     * @param Directory $out
     * @param string    $address
     * @return string|null
     */
    private static function resolvedFile(Directory $out, string $address): ?string
    {
        $path = rawurldecode(rtrim(substr($address, 0, strcspn($address, '?#')), '/'));

        $candidates = match (true) {
            $path === ''                               => ['index.html'],
            pathinfo($path, PATHINFO_EXTENSION) !== '' => [$path],
            default                                    => ["$path.html", "$path/index.html"],
        };

        foreach ($candidates as $candidate) {
            if ($out->file($candidate)->exists()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The page a link to another page's anchor lands on, as a file under $out — or null for an
     * address that is not one of the export's pages: another host, one written relative, or one the
     * address check has already reported.
     *
     * @param Directory $out
     * @param BasePath  $base
     * @param string    $address The link without its fragment.
     * @return string|null
     */
    private static function anchoredPage(Directory $out, BasePath $base, string $address): ?string
    {
        if (!str_starts_with($address, '/') || str_starts_with($address, '//') || $base->lacksBase($address)) {
            return null;
        }

        $file = self::resolvedFile($out, $base->withinExport($address));

        return $file !== null && str_ends_with($file, '.html') ? $file : null;
    }
}
