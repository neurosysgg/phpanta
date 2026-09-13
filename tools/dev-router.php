<?php

/**
 * Router for the `php -S` dev server, so it answers a versioned asset URL the way Apache does.
 *
 * `tools/build-assets.mjs` puts the build stamp in the path — `/assets/js/v-a1b2c3d4/main.js` — so
 * that a relative import specifier carries it without anything having to rewrite the file. The
 * segment is not a real directory; the server strips it. In production `public/.htaccess` does that
 * with a RewriteRule. The built-in server reads no `.htaccess` at all, so without this every
 * versioned URL would 404 locally while working live — the exact shape of bug this project has
 * already been bitten by once, when a shared host's handler list differed from the local setup.
 *
 * **This is one half of a mirror**, and the verify script pins that both halves strip the same
 * pattern. Change the shape in one and the check fails rather than the dev server quietly diverging.
 *
 * **Not a `Phpanta\Tool\Cli\Command`**, and cannot be: `php -S` loads this file per request and
 * reads a `bool` back. There is no argv and no exit code for a command interface to attach to.
 *
 * Dev-only: it is passed to `php -S` by a site's verify script and by anyone running the local
 * server. It is never deployed — a deploy ships `public/`, `src/`, `autoload.php` and `data/`, and
 * this is in none of them.
 *
 * Usage:
 *   php -S localhost:8080 -t public tools/dev-router.php
 */

declare(strict_types=1);

use Phpanta\Http\Header;
use Phpanta\Http\MimeType;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\TopLevelType;
use Phpanta\Support\Charset;

// The site's own autoloader, so a served asset declares its type the way a served document does.
// `public/index.php` requires the same file; this adds no dependency the dev server did not have.
// The project this serves is the one `php -S -t` was pointed at: its webroot, and the directory
// above it that holds the autoloader. Never where this file sits, which is the framework's tooling.
$public = (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '');

require_once dirname($public) . '/autoload.php';

/** The version segment, directly under the asset root. Mirrored in public/.htaccess. */
const VERSION_SEGMENT = '#^/assets/(js|css)/v-[0-9a-f]{8}/#';

/**
 * A path segment that starts with a dot, written plainly or percent-encoded: `.user.ini`, `.htaccess`,
 * `..`. None of them names anything the web should read — PHP's per-directory php.ini, Apache's own
 * configuration, a step out of the webroot — and the built-in server would hand every one of them
 * out, since it neither refuses `.ht*` nor resolves `..` the way Apache does. So they all go to the
 * site. `public/.htaccess` hides `.user.ini` the same way; the verify script pins both.
 */
const DOT_SEGMENT = '#(?:^|/)(?:\.|%2e)#i';

$target = $_SERVER['REQUEST_URI'] ?? '/';

// Uri rather than parse_url(), for the reasons Request gives: parse_url() fails with false where
// this wants null, and reads `//x/y` as a host. A target that opens with `//`, or that does not parse
// at all, is the site's to answer — the answer it gives it in production.
$path = str_starts_with($target, '//') ? null : Uri\Rfc3986\Uri::parse($target)?->getRawPath();

// Handed to the site rather than refused here, so it gets exactly the 404 an address that does not
// exist gets. Returning false would have the built-in server serve the file as it stands.
if ($path === null || preg_match(DOT_SEGMENT, $path) === 1) {
    require $public . '/index.php';

    return true;
}

// Not a versioned URL — hand it back to the built-in server, which serves real files and falls
// through to index.php for everything else. That is the whole of the normal path.
if (preg_match(VERSION_SEGMENT, $path, $stamped) !== 1) {
    return false;
}

// Only the stamped kind's own directory, and nothing outside it. Everything after the stamp came out
// of a URL, and is decoded here, so an encoded slash would otherwise walk it anywhere the process can
// read — realpath before is_file, and containment in public/assets/js/ or css/ before either.
$assets = realpath($public . '/assets/' . $stamped[1]);
$file   = realpath($public . '/assets/' . $stamped[1] . '/' . rawurldecode(substr($path, strlen($stamped[0]))));

$inside = $assets !== false && $file !== false && str_starts_with($file, $assets . DIRECTORY_SEPARATOR);

if (!$inside || !is_file($file)) {
    http_response_code(404);

    return true;
}

// A `MimeType` and not a string with `; charset=utf-8` stapled on, for the reason that class
// exists: `nosniff` stops a browser guessing the type and nothing stops it guessing the encoding,
// so the charset is the half that earns an object. It is the same object `ViewResponse` sends,
// which is the point — this file exists to answer the way the real server does, and answering with
// four hand-written strings was the one place it did not.
//
// The octet-stream fallback deliberately carries no charset: it is reached only by an extension
// `public/.htaccess` has no `SetHandler` for, so it names bytes rather than text.
$type = match (pathinfo($file, PATHINFO_EXTENSION)) {
    'js'    => new MimeType(TopLevelType::Text, 'javascript', Charset::Utf8),
    'css'   => new MimeType(TopLevelType::Text, 'css', Charset::Utf8),
    'map'   => new MimeType(TopLevelType::Application, 'json', Charset::Utf8),
    default => new MimeType(TopLevelType::Application, 'octet-stream', null),
};

header(new Header(ResponseHeader::ContentType, $type)->line());

readfile($file);

return true;
