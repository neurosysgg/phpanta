<?php

/**
 * The site's autoloader: the framework, then `PhpantaSite\` → `src/PhpantaSite/`, then the app.
 *
 * The same three steps as any site built on Phpanta. The one difference is where the framework is:
 * a site vendors it at `phpanta/`, and this site lives inside it, so it is one directory up.
 */

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'PhpantaSite\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/PhpantaSite/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

PhpantaSite\Site::boot();
