<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\App;
use Phpanta\DataFileName;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ViewResponse;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\Route;
use Phpanta\Text\Language;
use Phpanta\Text\LanguageAddresses;
use Phpanta\Text\Languages;
use Phpanta\View\Html\Vocabulary;
use Phpanta\View\Shell;

/**
 * Phpanta's own site, as the framework's app.
 *
 * The smallest real site there is: four pages, each a markup tree its view builds, in English and
 * German, with no data of its own and nothing from any other host. It is exported to static files
 * for GitHub Pages, which is why every route is a page, and why each language has an address of its
 * own — a static host cannot choose a language for a visitor, so each page is written once more in
 * each, and the client picks. See {@link \Phpanta\Text\LanguageAddresses}.
 */
final class Site extends App
{
    public const string NAME = 'Phpanta';

    public const string REPOSITORY = 'https://github.com/neurosysgg/phpanta';

    /**
     * @return string
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * `site/`: the directory holding this site's `autoload.php`.
     *
     * @return Directory
     */
    public function above(): Directory
    {
        return new Directory(dirname(__DIR__, 2));
    }

    /**
     * One route per page.
     *
     * @return Collection<Route>
     */
    public function routes(): Collection
    {
        $routes = new Collection(Route::class);

        foreach (Page::cases() as $page) {
            $routes = $routes->with(
                new Route($page->path(), static fn(): PageController => new PageController($page)),
            );
        }

        return $routes;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function notFound(Request $request): Response
    {
        return new ViewResponse(new NotFoundView(), HttpStatusCode::NotFound);
    }

    /**
     * English, then German.
     *
     * @return Languages
     */
    public function languages(): Languages
    {
        return new Languages(Language::English, Language::German);
    }

    /**
     * Each language at an address of its own — `rules.de.html` — as a static host needs.
     *
     * @return LanguageAddresses
     */
    public function languageAddresses(): LanguageAddresses
    {
        return LanguageAddresses::Suffixed;
    }

    /**
     * @return Shell
     */
    public function shell(): Shell
    {
        return new Layout();
    }

    /**
     * The standard vocabulary: the site parses nothing, so it declares nothing more.
     *
     * @return Vocabulary
     */
    public function vocabulary(): Vocabulary
    {
        return Vocabulary::standard();
    }

    /**
     * @return string
     */
    public function buildId(): string
    {
        return AssetManifest::SCRIPT;
    }

    /**
     * None: every page is built in PHP, and the site keeps nothing at runtime.
     *
     * @return Collection<DataFileName>
     */
    protected function ownDataFiles(): Collection
    {
        return new Collection(DataFileName::class);
    }
}
