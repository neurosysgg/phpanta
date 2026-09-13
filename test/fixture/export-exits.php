<?php

/**
 * Runs an export whose one page's controller ends the process, the way a controller behind a
 * password does under the CLI — for {@link \Phpanta\Test\Unit\ExportTest}, which cannot survive an
 * `exit` in its own process.
 *
 * Usage: php export-exits.php <deployment> <out>
 */

declare(strict_types=1);

use Phpanta\Test\Unit\ExportTest;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Command\Export;

require __DIR__ . '/../bootstrap.php';

Runner::run(
    new Export(ExportTest::appAt($argv[1], [ExportTest::exiting()])),
    [$argv[0], '--out', $argv[2], '--debug'],
);
