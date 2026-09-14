<?php

declare(strict_types=1);

namespace Hello;

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
use Phpanta\Text\Languages;
use Phpanta\View\Html\Document;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Vocabulary;
use Phpanta\View\Shell;
use Phpanta\View\View;

/**
 * The app: everything the framework asks of a site, answered in one class.
 */
final class Hello extends App implements Shell
{
    /**
     * @return string
     */
    public function name(): string
    {
        return 'Hello';
    }

    /**
     * The directory holding this example's `autoload.php`.
     *
     * @return Directory
     */
    public function above(): Directory
    {
        return new Directory(dirname(__DIR__, 2));
    }

    /**
     * @return Collection<Route>
     */
    public function routes(): Collection
    {
        return new Collection(Route::class)->with(
            new Route(HelloPath::World, static fn(): Greet => new Greet()),
            new Route(HelloPath::Someone, static fn(string $name): Greet => new Greet($name)),
        );
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function notFound(Request $request): Response
    {
        return new PlainTextResponse(HttpStatusCode::NotFound, HelloText::Nobody->in($request->language()) . "\n");
    }

    /**
     * @return Languages
     */
    public function languages(): Languages
    {
        return new Languages(Language::English, Language::German);
    }

    /**
     * @return Shell
     */
    public function shell(): Shell
    {
        return $this;
    }

    /**
     * The page around every view: its title in the head, its content in the body.
     *
     * @param View     $view
     * @param Language $language
     * @return Document
     */
    public function document(View $view, Language $language): Document
    {
        return new Document(
            new Element(HtmlTag::Html)->attr(HtmlAttribute::Lang, $language)->containing(
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
        return 'hello';
    }

    /**
     * @return Collection<DataFileName>
     */
    protected function ownDataFiles(): Collection
    {
        return new Collection(DataFileName::class);
    }
}
