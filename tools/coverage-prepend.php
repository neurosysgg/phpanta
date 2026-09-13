<?php

/**
 * Collects code coverage from the verify script's dev server.
 *
 * `test/basic_test.sh` covers what unit tests structurally cannot — the real HTTP stack, the
 * `exit`-ing auth code, the `header()` calls that are a no-op under CLI. None of that showed up in
 * a coverage report, because it runs in a different process with no instrumentation, so the number
 * read as if the site's 401, its 303 and its 405 were untested when they are the most thoroughly
 * exercised paths on it.
 *
 * This is loaded as `auto_prepend_file` for every request that server handles. It records line
 * coverage and writes it out from a shutdown function, which is the whole trick: a shutdown
 * function still runs when the request ends in `exit`, and every response on this site does.
 *
 * **Not a `Phpanta\Tool\Cli\Command`**, and cannot be: PHP loads this as `auto_prepend_file`
 * before the request's own code, so nothing invokes it and there is nothing to hand a status back to.
 *
 * Off unless `NEUROSYS_COVERAGE_DIR` names a directory, so a normal `composer verify` is
 * unaffected — see `composer coverage`, which is what sets it.
 */

declare(strict_types=1);

(static function (): void {
    $directory = getenv('NEUROSYS_COVERAGE_DIR');

    if ($directory === false || $directory === '' || !function_exists('xdebug_start_code_coverage')) {
        return;
    }

    xdebug_start_code_coverage();

    // The project is the one the server was started on — the parent of its webroot — not the
    // directory this file sits in, which is the framework's tooling.
    $root    = dirname((string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $sources = [$root . '/src/', $root . '/phpanta/src/'];

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
