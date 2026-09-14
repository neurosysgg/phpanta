<?php

/**
 * The example's PHPUnit bootstrap: composer for PHPUnit, then the example's autoloader — which loads
 * the framework and boots {@link \Hello\Hello} — then the framework's test support, for
 * {@link \Phpanta\Test\TestRequest}.
 */

declare(strict_types=1);

// Composer — the framework's own when it is checked out alone, the enclosing project's when it is
// vendored into one.
$vendor = is_file(dirname(__DIR__, 3) . '/vendor/autoload.php')
    ? dirname(__DIR__, 3) . '/vendor/autoload.php'
    : dirname(__DIR__, 4) . '/vendor/autoload.php';

require $vendor;
require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__, 3) . '/test/autoload.php';
