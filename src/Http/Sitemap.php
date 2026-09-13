<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Dom\XMLDocument;
use NoDiscard;
use Phpanta\App;
use Phpanta\Exception\AppException;
use Phpanta\Support\Charset;
use Phpanta\Support\Collection;
use Phpanta\Support\Route;

/**
 * The Sitemap class. Every page an app has, as the XML a search engine reads — built from the same
 * routes the router asks, so it cannot list a page that is not there or miss one that is.
 *
 * ```php
 * new Route(SitePath::Sitemap, static fn(): Controller => new SitemapController())
 * // whose handle() is: return Sitemap::of(Site::current());
 * ```
 *
 * **The pages are the ones a static export would write** — {@link Route::exportedPaths()}: a route
 * that only reads, at one address or at each value its `$exports` closure names. A route behind a
 * password says it has no pages, and so is not listed either.
 *
 * **The addresses are absolute**, which is the one thing a sitemap needs that nothing else here
 * does: each is the app's {@link App::origin()} and the path. An app that has not said its origin
 * cannot have a sitemap, and is told so rather than handed one of relative addresses nothing reads.
 *
 * **Written through the DOM, never as a string.** The XML is built by `Dom\XMLDocument`, so every
 * address is escaped by the parser that will read it back, and the rule that nothing writes markup
 * from a string holds here too.
 *
 * **No `hreflang` alternates**, and not for want of a place to put them: an app built on this
 * framework answers every language at one address, chosen per request, so there is no second
 * address for an alternate to name. **Nor a `<link rel="canonical">`**, for the same reason: a page
 * has one address already, and the one other spelling of it — with or without a trailing slash — is
 * {@link \Phpanta\Service\Layer\TrailingSlash}'s to answer with a 308, which says it more firmly
 * than a hint in the head would.
 */
final readonly class Sitemap implements Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Origin            $origin The origin every address is on.
     * @param Collection<Route> $routes The routes whose pages are listed.
     */
    public function __construct(
        private Origin     $origin,
        private Collection $routes,
    ) {}

    /**
     * The sitemap of $app: its origin, and every page in its route table.
     *
     * @param App $app
     * @return self
     * @throws AppException if the app has not said its origin.
     */
    public static function of(App $app): self
    {
        return new self(
            $app->origin() ?? throw new AppException(
                'A sitemap lists absolute addresses, so the app has to say its origin: override App::origin().',
            ),
            $app->routeTable(),
        );
    }

    /**
     * The sitemap, as XML — asked again whenever it is fetched, like any document here.
     *
     * @param Request $request
     * @return Answer
     */
    #[NoDiscard(
        'answer() works out what would be sent and sends nothing; a call whose result goes nowhere '
        . 'answered no one',
    )]
    public function answer(Request $request): Answer
    {
        $namespace = SitemapElement::namespace();
        $document  = XMLDocument::createEmpty();
        $urlSet    = $document->createElementNS($namespace, SitemapElement::UrlSet->value);

        $document->appendChild($urlSet);

        foreach ($this->routes as $route) {
            foreach ($route->exportedPaths() as $path) {
                $location = $document->createElementNS($namespace, SitemapElement::Location->value);
                $location->textContent = $this->origin->render() . $path;

                $url = $document->createElementNS($namespace, SitemapElement::Url->value);
                $url->appendChild($location);
                $urlSet->appendChild($url);
            }
        }

        return new Answer(
            HttpStatusCode::Ok,
            new Collection(Header::class)->with(
                new Header(ResponseHeader::ContentType, new MimeType(TopLevelType::Application, 'xml', Charset::Utf8)),
                new Header(ResponseHeader::CacheControl, CacheControl::revalidate()),
            ),
            new TextBody((string) $document->saveXml()),
        );
    }
}
