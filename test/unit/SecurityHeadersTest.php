<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\DataFileName;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\Security\CspDirective;
use Phpanta\Http\Security\CspHost;
use Phpanta\Http\Security\CspSource;
use Phpanta\Http\Security\PermissionsPolicy;
use Phpanta\Http\Security\PermissionsPolicyFeature;
use Phpanta\Http\Security\StrictTransportSecurity;
use Phpanta\Http\SecurityHeader;
use Phpanta\Http\SecurityHeaders;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\Route;
use Phpanta\Test\TestApp;
use Phpanta\Text\Language;
use Phpanta\Text\Languages;
use Phpanta\View\Html\Vocabulary;
use Phpanta\View\Shell;
use PHPUnit\Framework\TestCase;

/**
 * What an app may widen in the security headers, and what it is sent when it widens nothing.
 */
final class SecurityHeadersTest extends TestCase
{
    /**
     * The test app says nothing about any of the three, so it is sent every policy at its strictest.
     *
     * @return void
     */
    public function testAnAppThatWidensNothingIsSentTheStrictPolicies(): void
    {
        $headers = SecurityHeaders::headers(App::current());

        self::assertSame(
            'max-age=31536000; includeSubDomains',
            $headers[SecurityHeader::StrictTransportSecurity->value],
        );
        self::assertSame(
            "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; frame-src 'none'; "
            . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'",
            $headers[SecurityHeader::ContentSecurityPolicy->value],
        );
        self::assertSame(
            'geolocation=(), camera=(), microphone=(), payment=(), usb=(), midi=()',
            $headers[SecurityHeader::PermissionsPolicy->value],
        );
    }

    /**
     * Every fetch directive but two takes the hosts an app names, beside `'self'`; the three a
     * strict policy leaves to `default-src` appear only when there is a host to name. `default-src`
     * and `object-src` are never asked, so a host an app names for either goes nowhere.
     *
     * @return void
     */
    public function testEveryFetchDirectiveTakesTheHostsAnAppNames(): void
    {
        self::assertSame(
            "default-src 'self'; "
            . "script-src 'self' https://scripts.example.com; "
            . "style-src 'self' https://styles.example.com; "
            . "img-src 'self' https://images.example.com; "
            . 'frame-src https://frames.example.com; '
            . "connect-src 'self' https://api.example.com; "
            . "media-src 'self' https://media.example.com; "
            . "font-src 'self' https://fonts.example.com; "
            . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'",
            SecurityHeaders::contentSecurityPolicy(self::widened())->render(),
        );
    }

    /**
     * The transport and permissions policies are the app's to loosen, and the header sends what
     * it answers.
     *
     * @return void
     */
    public function testTheTransportAndPermissionsPoliciesAreTheAppsToLoosen(): void
    {
        $headers = SecurityHeaders::headers(self::widened());

        self::assertSame('max-age=86400', $headers[SecurityHeader::StrictTransportSecurity->value]);
        self::assertSame('camera=(), microphone=()', $headers[SecurityHeader::PermissionsPolicy->value]);
    }

    /**
     * An app that names a host under every directive and loosens both policies.
     *
     * @return App
     */
    private static function widened(): App
    {
        return new class () extends App {
            /** @return string */
            public function name(): string
            {
                return 'widened';
            }

            /** @return Directory */
            public function above(): Directory
            {
                return new Directory(sys_get_temp_dir());
            }

            /** @return Collection<Route> */
            public function routes(): Collection
            {
                return new Collection(Route::class);
            }

            /**
             * @param Request $request
             * @return Response
             */
            public function notFound(Request $request): Response
            {
                return new PlainTextResponse(HttpStatusCode::NotFound, "404\n");
            }

            /** @return Languages */
            public function languages(): Languages
            {
                return new Languages(Language::English);
            }

            /** @return Shell */
            public function shell(): Shell
            {
                return new TestApp();
            }

            /** @return Vocabulary */
            public function vocabulary(): Vocabulary
            {
                return Vocabulary::standard();
            }

            /** @return string */
            public function buildId(): string
            {
                return 'widened';
            }

            /**
             * @param CspDirective $directive
             * @return Collection<CspSource>
             */
            public function contentHosts(CspDirective $directive): Collection
            {
                $host = match ($directive) {
                    CspDirective::ScriptSrc  => 'https://scripts.example.com',
                    CspDirective::StyleSrc   => 'https://styles.example.com',
                    CspDirective::ImgSrc     => 'https://images.example.com',
                    CspDirective::FrameSrc   => 'https://frames.example.com',
                    CspDirective::ConnectSrc => 'https://api.example.com',
                    CspDirective::MediaSrc   => 'https://media.example.com',
                    CspDirective::FontSrc    => 'https://fonts.example.com',
                    default                  => 'https://nowhere.example.com',
                };

                return new Collection(CspSource::class)->with(new CspHost($host));
            }

            /** @return StrictTransportSecurity */
            public function strictTransportSecurity(): StrictTransportSecurity
            {
                return new StrictTransportSecurity(StrictTransportSecurity::ONE_DAY, includeSubDomains: false);
            }

            /** @return PermissionsPolicy */
            public function permissionsPolicy(): PermissionsPolicy
            {
                return PermissionsPolicy::deny(PermissionsPolicyFeature::Camera, PermissionsPolicyFeature::Microphone);
            }

            /** @return Collection<DataFileName> */
            protected function ownDataFiles(): Collection
            {
                return new Collection(DataFileName::class);
            }
        };
    }
}
