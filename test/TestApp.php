<?php

declare(strict_types=1);

namespace Phpanta\Test;

use Phpanta\App;
use Phpanta\DataFileName;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\Route;
use Phpanta\Text\Language;
use Phpanta\Text\LanguageAddresses;
use Phpanta\Text\Languages;
use Phpanta\View\Html\Document;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Vocabulary;
use Phpanta\View\Shell;
use Phpanta\View\View;

/**
 * The app the framework's own tests run under: the smallest thing that is one.
 *
 * No routes of its own — the framework's API route is all its table holds — no data files beyond
 * the framework's credentials, the standard vocabulary, both of the framework's languages, and a
 * shell that is a document around a view and nothing else. Its deployment is a fixture directory
 * holding an empty webroot, so the paths the framework derives from an app have somewhere real to
 * land without ever landing in a repository.
 */
final class TestApp extends App implements Shell
{
    /**
     * @return string
     */
    public function name(): string
    {
        return 'phpanta';
    }

    /**
     * The fixture deployment: `fixture/app/`, beside this file, with a `public/` in it.
     *
     * @return Directory
     */
    public function above(): Directory
    {
        return new Directory(__DIR__ . '/fixture/app');
    }

    /**
     * @return Collection<Route>
     */
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

    /**
     * @return Languages
     */
    public function languages(): Languages
    {
        return new Languages(Language::English, Language::German);
    }

    /**
     * Each language at an address of its own, so the suite runs the one mode whose every line is
     * something to assert: `/x.de.html` is `/x` in German. No test here asks for such a path unless
     * it means to.
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
        return $this;
    }

    /**
     * A document around the view: its title, and its content in the body.
     *
     * @param View     $view
     * @param Language $language
     * @return Document
     */
    public function document(View $view, Language $language): Document
    {
        return new Document(
            new Element(HtmlTag::Html)
                ->attr(HtmlAttribute::Lang, $language)
                ->containing(
                    new Element(HtmlTag::Head)->containing(new Element(HtmlTag::Title)->containing($view->pageTitle())),
                    new Element(HtmlTag::Body)->containing($view->content()),
                ),
        );
    }

    /**
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
        return 'test';
    }

    /**
     * @return Collection<DataFileName>
     */
    protected function ownDataFiles(): Collection
    {
        return new Collection(DataFileName::class);
    }
}
