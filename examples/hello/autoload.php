<?php

/**
 * The example's autoloader: the framework, then `Hello\` → `src/Hello/`, then the app.
 *
 * The same three steps as any site built on Phpanta. A site vendors the framework at `phpanta/`
 * and requires `phpanta/autoload.php`; this one lives inside the framework, two directories down.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Hello\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/Hello/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

Hello\Hello::boot();
