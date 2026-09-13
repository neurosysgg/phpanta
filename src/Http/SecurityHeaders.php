<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\App;
use Phpanta\Http\Security\ContentSecurityPolicy;
use Phpanta\Http\Security\ContentTypeOptions;
use Phpanta\Http\Security\CspDirective;
use Phpanta\Http\Security\CspKeyword;
use Phpanta\Http\Security\PermissionsPolicyFeature;
use Phpanta\Http\Security\ReferrerPolicy;
use Phpanta\Support\BareArray;
use Phpanta\Support\Collection;

/**
 * The SecurityHeaders class. Emits the site's response security headers.
 *
 * Sent from `public/index.php` before anything is dispatched, so they cover every response the
 * application produces — including the 401 {@link \Phpanta\Service\Auth} exits with, the 405
 * {@link \Phpanta\Router} refuses a write method with, and every redirect.
 *
 * Every value here is a typed object rather than a header string: see {@link CspDirective},
 * {@link Security\CspSource}, {@link ReferrerPolicy} and {@link PermissionsPolicyFeature}. A misspelled
 * directive or an unquoted `'self'` is a parse error now, not a header the browser drops.
 *
 * Three of the five are the app's to widen — {@link App::contentHosts()},
 * {@link App::strictTransportSecurity()} and {@link App::permissionsPolicy()} — and each defaults
 * to the strict answer. The referrer policy and `nosniff` are not: nothing a site does needs
 * either loosened.
 *
 * Static assets are served straight by Apache and never reach PHP, so they don't get these.
 * That is fine for what `public/assets/` holds; if it ever holds something user-supplied, add
 * the headers to `public/.htaccess` behind an `<IfModule mod_headers.c>` guard instead — an
 * unguarded `Header` directive 500s the whole site where mod_headers isn't loaded.
 */
final class SecurityHeaders
{
    /**
     * Sends every security header, and unsends the one PHP adds by itself.
     *
     * `X-Powered-By` carries the exact patch version — `PHP/8.5.9`, not `PHP/8.5` — and PHP
     * appends it before any of this code runs, which is why it is removed here rather than
     * simply absent from {@link self::headers()}. The real switch is `expose_php`, and that is
     * php.ini's, which is not ours to set on shared hosting; `header_remove()` is the half of it
     * we control. Nothing needs the header, and a version string is free reconnaissance: it
     * turns "find a PHP bug" into "look up the CVEs for 8.5.9".
     *
     * Safe to call before any output.
     *
     * @return void
     */
    public static function send(): void
    {
        header_remove(ResponseHeader::PoweredBy->value);

        foreach (self::all() as $header) {
            header($header->line());
        }
    }

    /**
     * Every header this class sends, as the typed pairs it sends them as.
     *
     * Each is a {@link SecurityHeader} case beside its {@link HeaderValue}, so no case is flattened to
     * a string and parsed back with `from()` one line later. See docs/security.md.
     *
     * A {@link Collection} rather than a list, because that is already what
     * {@link ViewResponse}, {@link PlainTextResponse} and {@link FileResponse} each take: the
     * headers a document sends were inside the type and the headers *every* response sends were
     * not, which is the wrong way round for the five that cover the 401 as well as the 200.
     *
     * This runs on every request, so it is measured rather than assumed: **34.58 µs**, about 1.4 µs
     * more than a plain list for the construction plus one variadic `with()`, and in line with the
     * 1.76 µs a `Collection` is documented to cost before it holds anything.
     *
     * @param App|null $app The app whose headers these are: the booted one, unless a test hands in
     *                      another to ask what a different app would be sent.
     * @return Collection<Header>
     */
    public static function all(?App $app = null): Collection
    {
        $app ??= App::current();

        return new Collection(Header::class)->with(
            new Header(SecurityHeader::StrictTransportSecurity, $app->strictTransportSecurity()),
            new Header(SecurityHeader::ContentSecurityPolicy, self::contentSecurityPolicy($app)),
            new Header(SecurityHeader::ReferrerPolicy, self::referrerPolicy()),
            new Header(SecurityHeader::ContentTypeOptions, ContentTypeOptions::NoSniff),
            new Header(SecurityHeader::PermissionsPolicy, $app->permissionsPolicy()),
        );
    }

    /**
     * Returns every header this class sends, keyed by header name.
     *
     * Public because it is the honest answer to "what does the site send?" — and it stays a
     * name-to-string map rather than becoming a list of objects, because that is the shape its
     * readers want: the tests ask it what a named header says, and so can a client test, which
     * shells out to PHP for the `Permissions-Policy` to check an embedded player is not denied
     * something it needs. Rendering {@link self::all()} is a view over the typed list, not a
     * second statement of it.
     *
     * @param App|null $app As for {@link self::all()}.
     * @return array<string, string>
     */
    #[BareArray(
        'the door: a rendered view of self::all() for readers outside the type, some of them '
        . 'outside PHP — a client test can shell out for a single header by name. A '
        . 'SearchableCollection here would be a second statement of the typed list rather than a '
        . 'view over it.',
    )]
    public static function headers(?App $app = null): array
    {
        $rendered = [];

        foreach (self::all($app) as $header) {
            $rendered[$header->name->headerName()] = $header->value->render();
        }

        return $rendered;
    }

    /**
     * Builds the Content-Security-Policy.
     *
     * `script-src` is strict — there are no inline handlers or inline scripts left in any view,
     * which is the directive that actually blocks XSS.
     *
     * `style-src` is strict too, with no {@link CspKeyword::UnsafeInline}. No view emits an inline
     * style, and a test enforces it; an element that has to style itself at run time sets the
     * properties through the CSSOM — element.style, which CSP does not govern — so the allowance
     * would have nothing to cover.
     *
     * `img-src` carries no {@link Security\CspScheme::Data}, for the same reason: a placeholder
     * image can be a file, and nothing else needs one. The allowance would widen the one directive
     * that governs where bytes may be fetched from — and `data:` in `img-src` is a documented
     * exfiltration channel for an attacker who has already found an injection. A page's images
     * are its own and whatever hosts the app names, and those are what it says.
     *
     * **Every fetch directive but two takes the app's hosts** — see {@link App::contentHosts()}.
     * `connect-src`, `media-src` and `font-src` are written only when the app names one, because
     * `default-src 'self'` already says everything they would say without one; a policy that
     * wrote them anyway would be longer and no stricter.
     *
     * **There is deliberately no `report-uri` or `report-to`**, and the reason is worth having
     * written down, because on a policy this strict a reporting endpoint is the obvious next
     * suggestion. It would be a good one on most sites. Here it collides with three things the
     * framework has decided on purpose:
     *
     * - A report is a **POST**. {@link \Phpanta\Router::dispatch()} answers anything but GET and
     *   HEAD with a 405, the `Allow` header is derived from {@link HttpMethod::isReadOnly()} so it
     *   cannot claim otherwise, and the suites assert it. A first-party endpoint means carving an
     *   exception into the one gate whose whole value is having none.
     * - A third-party collector is a third-party origin, receiving a request from every visitor,
     *   before any consent. That is the arrangement a site vendors its third-party assets to avoid,
     *   and a consent gate in front of an embed exists to defer.
     * - A report carries `document-uri`, `referrer` and `blocked-uri`. Collecting those is a
     *   privacy-policy decision before it is a code one: a site's privacy policy would have to
     *   claim that data before a report could carry it.
     *
     * `report-to` also wants a `Reporting-Endpoints` header, which would be a sixth
     * {@link SecurityHeader} case naming an endpoint that does not exist. What stands in for
     * reporting here is that the policy is asserted rather than observed: the suites pin the
     * directive set and the hosts it names, and fail on an inline style or handler in any view. A
     * future change that would violate this policy fails the build instead of a stranger's browser.
     *
     * @param App|null $app As for {@link self::all()}.
     * @return ContentSecurityPolicy
     */
    public static function contentSecurityPolicy(?App $app = null): ContentSecurityPolicy
    {
        $app  ??= App::current();
        $policy = new ContentSecurityPolicy()->allow(CspDirective::DefaultSrc, CspKeyword::SelfOrigin);

        foreach ([CspDirective::ScriptSrc, CspDirective::StyleSrc, CspDirective::ImgSrc] as $directive) {
            $policy = $policy->allow(
                $directive,
                CspKeyword::SelfOrigin,
                ...$app->contentHosts($directive)->toValues(),
            );
        }

        // Nothing of the site's own is framed, so a site that frames nobody else frames nothing.
        $frames = $app->contentHosts(CspDirective::FrameSrc);
        $policy = $policy->allow(
            CspDirective::FrameSrc,
            ...($frames->isEmpty() ? [CspKeyword::None] : $frames->toValues()),
        );

        foreach ([CspDirective::ConnectSrc, CspDirective::MediaSrc, CspDirective::FontSrc] as $directive) {
            $hosts = $app->contentHosts($directive);

            if (!$hosts->isEmpty()) {
                $policy = $policy->allow($directive, CspKeyword::SelfOrigin, ...$hosts->toValues());
            }
        }

        return $policy
            ->allow(CspDirective::BaseUri, CspKeyword::SelfOrigin)
            ->allow(CspDirective::FormAction, CspKeyword::SelfOrigin)
            ->allow(CspDirective::FrameAncestors, CspKeyword::None)
            ->allow(CspDirective::ObjectSrc, CspKeyword::None);
    }

    /**
     * A redirect to a file host hands it the page's full URL as a `Referer` otherwise, and a framed
     * embed receives the full page URL once it loads. Same-origin navigation keeps the path, so
     * SPA links still work as expected.
     *
     * @return ReferrerPolicy
     */
    private static function referrerPolicy(): ReferrerPolicy
    {
        return ReferrerPolicy::StrictOriginWhenCrossOrigin;
    }
}
