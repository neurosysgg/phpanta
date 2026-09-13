<?php

declare(strict_types=1);

/*
 * Phpanta's tooling autoloader: `Phpanta\Tool\` → `lib/`, beside this file.
 *
 * Separate from the framework's own autoload.php because the tooling is never deployed: the
 * server has `phpanta/src/` and nothing else of this directory, so nothing it loads should know
 * the tooling exists. A site's own tools/autoload.php requires this, and a command or a test that
 * needs the tooling requires that.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Phpanta\\Tool\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
