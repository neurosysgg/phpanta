<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

use Dom\HTMLDocument;
use MessageFormatter;
use OpenSSLAsymmetricKey;
use Uri\Rfc3986\Uri;
use Uri\WhatWg\Url;

/**
 * The PhpExtension enum. The five extensions the framework is a fatal without, and how to prove
 * each one is really there.
 *
 * **They were already named twice, and neither place is the host.** `composer.json` requires all
 * five, and composer never runs on the server — `vendor/` is not deployed. The verify script
 * asks for them by name in its Environment block, and that block runs `php` from `$PATH` on
 * whichever machine is running the suite. So the two statements of this fact both describe a
 * developer's PHP, and the one runtime that matters has never been asked. That gap is what
 * `health v1 extensions` exists to close: {@link \Phpanta\Support\RequirementInitialization}
 * declares each case a required {@link ExtensionRequirement}, with {@link self::isPresent()} as its
 * proof, and this enum is the vocabulary it asks in.
 *
 * **Each case proves itself by naming what the framework actually uses, rather than by asking
 * whether the extension is registered.** That standard is not new here — the verify script already
 * states it for `ext/dom`: registered and working are two questions, and the second is the one
 * worth an answer. `extension_loaded()` answers the first only, and answers it about a name rather
 * than about a capability.
 *
 * Server-only, like every other enum under `Http\Api`: nothing the browser loads may reach the admin,
 * so there is no TypeScript mirror and none is wanted.
 */
enum PhpExtension: string
{
    /**
     * The WHATWG and RFC 3986 URL parsers.
     *
     * Bundled with PHP 8.5 rather than optional, which is not the same as present — a host can
     * build without it. Its absence is a fatal on **every request**:
     * {@link \Phpanta\View\Html\Element::staysOnThisOrigin()} resolves an attribute's URL the way
     * a browser would, and {@link \Phpanta\Http\Request::path()} reads the request target with
     * the RFC 3986 parser. Both are on the path of every response an app sends.
     */
    case Uri = 'uri';

    /**
     * PHP 8.4's WHATWG HTML parser.
     *
     * The narrowest failure of the five and the easiest to miss: it is a fatal on a page that
     * carries hand-authored HTML and on nothing else, because {@link \Phpanta\View\Html\MarkupParser}
     * is the only reader. That page is often a legal one — an obligation rather than a choice — and
     * the one page a smoke test of an app's own markup would never reach.
     */
    case Dom = 'dom';

    /**
     * ICU, for the text layer: `MessageFormatter` formats every translated string — its arguments,
     * its plurals, and a language's own way of writing a number (`1.000` in German, `1,000` in
     * English).
     *
     * **Declared before anything uses it, and the order is the point.** A shared host may have it
     * where a local runtime does not — Arch ships it commented out in php.ini — which is the
     * dangerous direction: code that reached for it would work live and fail every test.
     * Declared first, a runtime without it fails `health v1` and the verify script
     * before a single page depends on it.
     */
    case Intl = 'intl';

    /**
     * What {@link \Phpanta\Support\PublicKey} verifies a signature with.
     *
     * Not bundled the way {@link self::Uri} is, and it fails the quiet way: a fatal on a push, on
     * the one route built to give a stranger one answer however it is asked. The probe is
     * {@link OpenSSLAsymmetricKey}, which is the type `PublicKey` names in its own signature —
     * asking for the class the framework holds is asking for the extension that defines it, and it
     * keeps the function names where the verify script pins them, which is one file.
     */
    case OpenSsl = 'openssl';

    /**
     * What unpacks a pushed payload, in one call in {@link \Phpanta\Service\UpdateApplier}.
     *
     * Same failure as {@link self::OpenSsl} and the same silence: a push against a deployment
     * without it is a fatal, and every other reason the admin refuses a stranger is one answer that
     * does not say which.
     */
    case Zlib = 'zlib';

    /**
     * Whether this extension is here **and working**, asked by using it.
     *
     * `::class` on an extension's own class resolves lexically, so naming one costs nothing on a
     * runtime that does not have it — there is no import to fail and no call to make. `gzdecode`
     * has no class to name, so it is the function `UpdateApplier` itself calls: the honest probe
     * for an extension is the thing the framework would reach for.
     *
     * @return bool
     */
    public function isPresent(): bool
    {
        return match ($this) {
            self::Uri     => class_exists(Url::class) && class_exists(Uri::class),
            self::Dom     => class_exists(HTMLDocument::class),
            self::Intl    => class_exists(MessageFormatter::class),
            self::OpenSsl => class_exists(OpenSSLAsymmetricKey::class),
            self::Zlib    => function_exists('gzdecode'),
        };
    }
}
