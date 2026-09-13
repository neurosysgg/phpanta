<?php

declare(strict_types=1);

/*
 * Phpanta's autoloader: `Phpanta\` → `src/`, beside this file.
 *
 * A site's own autoload.php requires this before it registers its own prefix, and that is all it
 * takes for the framework to load: there is no composer on the server, so there is nothing else to
 * ask. The same shape as the site's — a prefix, a directory, and a file required only if it is
 * there, so a class this cannot find falls through to the next autoloader rather than failing here.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Phpanta\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/src/' . ($prefix
            |> strlen(...)
            |> (fn($x) => substr($class, $x))
            |> (fn($x) => str_replace('\\', '/', $x) . '.php'));

    if (is_file($file)) {
        require $file;
    }
});
