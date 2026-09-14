<?php

/**
 * Collects code coverage from the dev server a site's end-to-end suite runs.
 *
 * A site's end-to-end verify script covers what unit tests structurally cannot — the real HTTP stack, the
 * front controller, the `header()` calls that are a no-op under CLI. None of that showed up in
 * a coverage report, because it runs in a different process with no instrumentation, so the number
 * read as if the lines that put every answer on the wire were untested when they run on every
 * request.
 *
 * This is loaded as `auto_prepend_file` for every request that server handles. It records line
 * coverage and writes it out from a shutdown function, which is the whole trick: a shutdown
 * function runs however the request ends — once the answer is sent, or after a fatal.
 *
 * **Not a `Phpanta\Tool\Cli\Command`**, and cannot be: PHP loads this as `auto_prepend_file`
 * before the request's own code, so nothing invokes it and there is nothing to hand a status back to.
 *
 * Off unless `PHPANTA_COVERAGE_DIR` names a directory, so a normal verify run is unaffected — a
 * site's coverage script is what sets it.
 */

declare(strict_types=1);

(static function (): void {
    $directory = getenv('PHPANTA_COVERAGE_DIR');

    if ($directory === false || $directory === '' || !function_exists('xdebug_start_code_coverage')) {
        return;
    }

    xdebug_start_code_coverage();

    // The project is the one the server was started on — the parent of its webroot — and the
    // framework is the one this file belongs to, wherever it is vendored: its tooling's parent.
    // Real paths both, because those are what Xdebug reports.
    $root    = dirname((string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $sources = [$root . '/src/', (string) realpath(dirname(__DIR__) . '/src') . '/'];

    register_shutdown_function(static function () use ($directory, $sources): void {
        // Only this site's own code: the dumps are written per request, and carrying the whole
        // include tree in each of them turns a hundred requests into tens of megabytes.
        $coverage = array_filter(
            xdebug_get_code_coverage(),
            static fn(string $file): bool => array_any(
                $sources,
                static fn(string $source): bool => str_starts_with($file, $source),
            ),
            ARRAY_FILTER_USE_KEY,
        );

        if ($coverage === []) {
            return;
        }

        file_put_contents(
            $directory . '/' . bin2hex(random_bytes(8)) . '.cov',
            serialize($coverage),
        );
    });
})();
