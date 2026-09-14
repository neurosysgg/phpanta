<?php

declare(strict_types=1);

use Hello\Hello;

require __DIR__ . '/../autoload.php';

// The last resort: whatever escapes the app is logged and answered with a bare 500.
set_exception_handler(static function (Throwable $fault): void {
    error_log('Hello: uncaught ' . $fault::class . ' — ' . $fault->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
    }
});

Hello::current()->run();
