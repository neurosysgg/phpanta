<?php

/**
 * The framework's PHPUnit bootstrap.
 *
 * Its tests run under {@link \Phpanta\Test\TestApp} rather than any site, so what they prove is
 * true of the framework on its own: a test that only passed with a site's languages, routes or
 * data files booted would be a test of the site.
 */

declare(strict_types=1);

// Composer — the framework's own when it is checked out alone, the project's when it is vendored
// into one. Composer provides PHPUnit; the framework's classes load through its own autoloader.
$vendor = is_file(dirname(__DIR__) . '/vendor/autoload.php')
    ? dirname(__DIR__) . '/vendor/autoload.php'
    : dirname(__DIR__, 2) . '/vendor/autoload.php';

require $vendor;
require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/tools/autoload.php';
require __DIR__ . '/autoload.php';

/** Absolute path to the framework's own directory. */
define('PHPANTA_ROOT', dirname(__DIR__));

Phpanta\Test\TestApp::boot();
