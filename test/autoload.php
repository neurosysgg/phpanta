<?php

declare(strict_types=1);

/*
 * The framework's test support: `Phpanta\Test\Unit\` → `unit/`, and the rest of `Phpanta\Test\` →
 * this directory.
 *
 * Mapped by hand for the same reason the site maps its own: there is no composer on the server, and
 * a framework checked out alone has no project composer.json to ask either. The two prefixes are
 * spelled out rather than derived, because the test directory is lower case and the namespace
 * segment is not — `Unit/` would never find `unit/` on a case-sensitive filesystem.
 *
 * A site's own test bootstrap requires this too, so its tests can use the fixtures the framework's
 * tests are built on rather than keeping copies of their own.
 */
spl_autoload_register(static function (string $class): void {
    foreach (['Phpanta\\Test\\Unit\\' => __DIR__ . '/unit/', 'Phpanta\\Test\\' => __DIR__ . '/'] as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
});
