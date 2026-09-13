<?php

declare(strict_types=1);

use Phpanta\Exception\SiteException;
use PhpantaSite\Site;

require __DIR__ . '/../autoload.php';

/*
 * The last resort, installed before anything can need it, and depending on nothing — no Response,
 * no view — because it runs when other code did not. It logs, answers a bare 500 if the headers
 * have not gone out, and says nothing else to the visitor.
 *
 * Only `npm run site:dev` runs this. What GitHub Pages serves is the export, which has no PHP in it.
 */
set_exception_handler(static function (Throwable $fault): void {
    error_log(sprintf(
        'Phpanta site: uncaught %s %s at %s:%d — %s',
        $fault::class,
        $fault instanceof SiteException ? '(from this repository)' : '(from underneath it)',
        $fault->getFile(),
        $fault->getLine(),
        $fault->getMessage(),
    ));

    if (headers_sent()) {
        return;
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    echo "500\n";
});

Site::current()->run();
