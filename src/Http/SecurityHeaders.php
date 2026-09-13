<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\App;
use Phpanta\Http\Security\ContentSecurityPolicy;
use Phpanta\Http\Security\ContentTypeOptions;
use Phpanta\Http\Security\CspDirective;
use Phpanta\Http\Security\CspKeyword;
use Phpanta\Http\Security\PermissionsPolicy;
use Phpanta\Http\Security\PermissionsPolicyFeature;
use Phpanta\Http\Security\ReferrerPolicy;
use Phpanta\Http\Security\StrictTransportSecurity;
use Phpanta\Support\BareArray;
use Phpanta\Support\Collection;

/**
 * The SecurityHeaders class. Emits the site's response security headers.
 *
 * Sent from `public/index.php` before anything is dispatched, so they cover every response the
 * application produces — including the 401 {@link \Phpanta\Service\Auth} exits with, the 405
 * {@link \Phpanta\Router} refuses a write method with, and the 303 a download redirects with.
 *
 * Every value here is a typed object rather than a header string: see {@link CspDirective},
 * {@link CspSource}, {@link ReferrerPolicy} and {@link PermissionsPolicyFeature}. A misspelled
 * directive or an unquoted `'self'` is a parse error now, not a header the browser drops.
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
     * a string and parsed back with `from()` one line later. See docs/history/security.md.
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
     * @return Collection<Header>
     */
    public static function all(): Collection
    {
        return new Collection(Header::class)->with(
            new Header(SecurityHeader::StrictTransportSecurity, self::strictTransportSecurity()),
            new Header(SecurityHeader::ContentSecurityPolicy, self::contentSecurityPolicy()),
            new Header(SecurityHeader::ReferrerPolicy, self::referrerPolicy()),
            new Header(SecurityHeader::ContentTypeOptions, ContentTypeOptions::NoSniff),
            new Header(SecurityHeader::PermissionsPolicy, self::permissionsPolicy()),
        );
    }

    /**
     * Returns every header this class sends, keyed by header name.
     *
     * Public because it is the honest answer to "what does the site send?" — and it stays a
     * name-to-string map rather than becoming a list of objects, because that is the shape its
     * readers want: the tests ask it what a named header says, and so does
     * `test/js/soundcloud-player.test.mjs`, which shells out to PHP for the `Permissions-Policy`
     * to check the player is not denied something it needs. Rendering {@link self::all()} is a
     * view over the typed list, not a second statement of it.
     *
     * @return array<string, string>
     */
    #[BareArray(
        'the door: a rendered view of self::all() for readers outside the type, one of them '
        . 'outside PHP — test/js/soundcloud-player.test.mjs shells out for a single header by '
        . 'name. A SearchableCollection here would be a second statement of the typed list rather '
        . 'than a view over it.',
    )]
    public static function headers(): array
    {
        $rendered = [];

        foreach (self::all() as $header) {
            $rendered[$header->name->headerName()] = $header->value->render();
        }

        return $rendered;
    }

    /**
     * Builds the Strict-Transport-Security policy.
     *
     * The site is read-only and sets no cookie, so the thing this protects is the credentials on
     * the two Basic Auth gates — the admin one, and the pre-launch one that runs on every single
     * request. Basic is base64. Over plaintext it is readable, and the `.htaccess` redirect cannot
     * help the request that carried it. See {@link StrictTransportSecurity}, and note the ramp
     * documented on its ONE_DAY constant before raising this on an estate you have not checked.
     *
     * @return StrictTransportSecurity
     */
    public static function strictTransportSecurity(): StrictTransportSecurity
    {
        return new StrictTransportSecurity();
    }

    /**
     * Builds the Content-Security-Policy.
     *
     * `script-src` is strict — there are no inline handlers or inline scripts left in any view,
     * which is the directive that actually blocks XSS.
     *
     * `style-src` is strict too. It carried {@link CspKeyword::UnsafeInline} for as long as
     * SoundCloud's attribution block was reproduced as HTML with inline `style` attributes. That
     * block is built by `<soundcloud-player>` now, which sets the same properties through the
     * CSSOM — element.style, which CSP does not govern — so the styling is unchanged and the
     * allowance has nothing left to cover. Nothing else emits an inline style; a test enforces it.
     *
     * `img-src` carried {@link Security\CspScheme::Data} on the same terms, and lost it for the same
     * reason. The comment on that case said the cover placeholder needed it; the placeholder is a
     * self-contained SVG that references nothing at all, and no page, stylesheet or element on
     * this site emits a `data:` image. So the allowance covered nothing while widening the one
     * directive that governs where bytes may be fetched from — and `data:` in `img-src` is a
     * documented exfiltration channel for an attacker who has already found an injection.
     * The site's own images are the placeholder and whatever HiDrive serves, and those are what
     * it now says.
     *
     * **There is deliberately no `report-uri` or `report-to`**, and the reason is worth having
     * written down, because on a policy this strict a reporting endpoint is the obvious next
     * suggestion. It would be a good one on most sites. Here it collides with three things this
     * one has decided on purpose:
     *
     * - A report is a **POST**. {@link \Phpanta\Router::dispatch()} answers anything but GET and
     *   HEAD with a 405, the `Allow` header is derived from {@link HttpMethod::isReadOnly()} so it
     *   cannot claim otherwise, and both suites assert it. A first-party endpoint means carving an
     *   exception into the one gate whose whole value is having none.
     * - A third-party collector is a third-party origin, receiving a request from every visitor,
     *   before any consent. That is the arrangement `docs/branding.md` vendors the brand icons to
     *   avoid and the arrangement `<soundcloud-player>`'s gate exists to defer.
     * - A report carries `document-uri`, `referrer` and `blocked-uri`. Collecting those is a
     *   privacy-policy decision before it is a code one, on exactly the terms
     *   {@link Site::DOWNLOAD_LOGGING} is switched off on: the privacy policy makes no such
     *   claim, so it would have to be amended first.
     *
     * `report-to` also wants a `Reporting-Endpoints` header, which would be a sixth
     * {@link SecurityHeader} case naming an endpoint that does not exist. What stands in for
     * reporting here is that the policy is asserted rather than observed: `SecurityTest` pins the
     * hosts it names, `ViewTest` and the verify script both fail on an inline style or handler, and
     * `HtmlTest` checks every `Tag` case against the stylesheet. A future change that would violate
     * this policy fails the build instead of a stranger's browser.
     *
     * @return ContentSecurityPolicy
     */
    public static function contentSecurityPolicy(): ContentSecurityPolicy
    {
        $app    = App::current();
        $frames = $app->contentHosts(CspDirective::FrameSrc);

        return new ContentSecurityPolicy()
            ->allow(CspDirective::DefaultSrc, CspKeyword::SelfOrigin)
            ->allow(CspDirective::ScriptSrc, CspKeyword::SelfOrigin)
            ->allow(CspDirective::StyleSrc, CspKeyword::SelfOrigin)
            ->allow(
                CspDirective::ImgSrc,
                CspKeyword::SelfOrigin,
                ...$app->contentHosts(CspDirective::ImgSrc)->toValues(),
            )
            // Nothing of the site's own is framed, so a site that frames nobody else frames nothing.
            ->allow(CspDirective::FrameSrc, ...($frames->isEmpty() ? [CspKeyword::None] : $frames->toValues()))
            ->allow(CspDirective::BaseUri, CspKeyword::SelfOrigin)
            ->allow(CspDirective::FormAction, CspKeyword::SelfOrigin)
            ->allow(CspDirective::FrameAncestors, CspKeyword::None)
            ->allow(CspDirective::ObjectSrc, CspKeyword::None);
    }

    /**
     * A download 303 hands the release URL to HiDrive as a `Referer` otherwise, and the framed
     * player receives the full page URL once it loads. Same-origin navigation keeps the path, so
     * SPA links still work as expected.
     *
     * @return ReferrerPolicy
     */
    private static function referrerPolicy(): ReferrerPolicy
    {
        return ReferrerPolicy::StrictOriginWhenCrossOrigin;
    }

    /**
     * The site asks for none of these, so all of them are denied to everyone.
     *
     * Denies every {@link PermissionsPolicyFeature} case, which makes that enum the list of
     * things the site refuses rather than a catalogue of what exists — see its docblock before
     * adding a case, because `Permissions-Policy` also applies to the SoundCloud iframe.
     *
     * @return PermissionsPolicy
     */
    private static function permissionsPolicy(): PermissionsPolicy
    {
        return PermissionsPolicy::denyAll();
    }
}
