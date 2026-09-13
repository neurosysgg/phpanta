<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Dom\XMLDocument;
use Phpanta\App;
use Phpanta\Exception\AppException;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Origin;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\Sitemap;
use Phpanta\Http\SitemapElement;
use Phpanta\Support\Collection;
use Phpanta\Support\Route;
use Phpanta\Test\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every page an app has, as a search engine reads it — the pages a static export would write, on the
 * app's origin, and nothing else.
 */
#[CoversClass(Sitemap::class)]
#[CoversClass(SitemapElement::class)]
#[CoversClass(App::class)]
final class SitemapTest extends TestCase
{
    /**
     * Every exported page is listed once, absolute, in the sitemaps namespace; a route with no pages —
     * placeholders and no `$exports`, or one that says it has none — is not.
     *
     * @return void
     */
    public function testEveryPageIsListedAbsoluteAndNothingElse(): void
    {
        $answer = new Sitemap(
            Origin::of('https://example.org'),
            new Collection(Route::class)->with(
                new Route(ExportFixturePath::Home, EchoController::factory()),
                new Route(ExportFixturePath::Guide, EchoController::factory()),
                new Route(
                    ExportFixturePath::Page,
                    EchoController::factory(),
                    exports: static fn(): array => ['a b', 'c'],
                ),
                new Route(ExportFixturePath::Pair, EchoController::factory()),
                new Route(ExportFixturePath::Missing, EchoController::factory(), exports: static fn(): array => []),
            ),
        )->answer(TestRequest::get('/sitemap.xml')->request());

        self::assertSame(HttpStatusCode::Ok, $answer->status());
        self::assertSame(
            'application/xml; charset=utf-8',
            $answer->header(ResponseHeader::ContentType)?->value->render(),
        );

        $document  = XMLDocument::createFromString($answer->body());
        $locations = [];

        foreach ($document->getElementsByTagNameNS(SitemapElement::namespace(), 'loc') as $location) {
            $locations[] = $location->textContent;
        }

        self::assertSame(
            [
                'https://example.org/',
                'https://example.org/guide',
                'https://example.org/pages/a%20b',
                'https://example.org/pages/c',
            ],
            $locations,
        );
        self::assertSame('urlset', $document->documentElement?->localName);
    }

    /**
     * An app that has not said its origin cannot have a sitemap, and is told so.
     *
     * @return void
     */
    public function testAnAppWithNoOriginIsRefusedASitemap(): void
    {
        self::assertNull(App::current()->origin());

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('override App::origin()');

        (void) Sitemap::of(App::current());
    }
}
