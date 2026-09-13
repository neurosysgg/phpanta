<?php

/**
 * `php phpanta/tools/export.php --out <dir> [--base /path/] [--debug]` — a static copy of the site.
 *
 * Run from the project it exports, which is the nearest directory at or above the working one that
 * holds a `composer.json` — the rule the build tools use. Its `autoload.php` boots its app, and the
 * app is all the export needs, which is why this one command is the framework's entry point rather
 * than a thin caller in each site. See {@link \Phpanta\Tool\Command\Export}.
 */

declare(strict_types=1);

use Phpanta\App;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Command\Export;

$root = getcwd() ?: '.';

while (!is_file($root . '/composer.json')) {
    if (dirname($root) === $root) {
        fwrite(STDERR, "export: no composer.json at or above the working directory — run this from the"
            . " project it exports.\n");
        exit(2);
    }

    $root = dirname($root);
}

require $root . '/autoload.php';
require __DIR__ . '/autoload.php';

Runner::run(new Export(App::current()), $argv);
