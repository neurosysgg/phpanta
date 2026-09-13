<?php

/**
 * The site's PHPUnit bootstrap: composer for PHPUnit, then the site's own autoloader — which loads
 * the framework and boots {@link \PhpantaSite\Site} — then `PhpantaSite\Test\` → this directory.
 */

declare(strict_types=1);

// Composer — the framework's own when it is checked out alone, the enclosing project's when it is
// vendored into one.
$vendor = is_file(dirname(__DIR__, 2) . '/vendor/autoload.php')
    ? dirname(__DIR__, 2) . '/vendor/autoload.php'
    : dirname(__DIR__, 3) . '/vendor/autoload.php';

require $vendor;
require dirname(__DIR__) . '/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'PhpantaSite\\Test\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
