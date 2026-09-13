<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Http\CookieName;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\ViewResponse;
use Phpanta\Text\Language;
use Phpanta\Text\Translatable;
use Phpanta\Text\Verbatim;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;
use Phpanta\View\View;
use PHPUnit\Framework\TestCase;

/**
 * The two halves of a page a static export renders without a request ever arriving: a request it
 * makes up, and a response it reads rather than sends.
 */
final class SyntheticPageTest extends TestCase
{
    /**
     * A made-up request is an anonymous `GET` of the path, normalised as a real one would be.
     *
     * @return void
     */
    public function testASyntheticRequestIsAnAnonymousGet(): void
    {
        $request = Request::synthetic('/guide/', Language::English);

        self::assertSame(HttpMethod::Get, $request->method());
        self::assertTrue($request->isReadOnly());
        self::assertSame('/guide', $request->path());
        self::assertFalse($request->isAjax());
        self::assertSame('', $request->authUser());
        self::assertNull($request->cookies()->value(CookieName::Language));
    }

    /**
     * It asks for its language the way a browser does, so it is answered in it.
     *
     * @return void
     */
    public function testASyntheticRequestIsAnsweredInItsLanguage(): void
    {
        self::assertSame(Language::German, Request::synthetic('/', Language::German)->language());
        self::assertSame(Language::English, Request::synthetic('/', Language::English)->language());
    }

    /**
     * What a response would send, read without sending it: the whole document, in the app's shell
     * and the request's language — which is what a file on a static host has to be.
     *
     * @return void
     */
    public function testAViewResponseRendersTheDocumentItWouldSend(): void
    {
        $markup = new ViewResponse(self::view())->render(Request::synthetic('/', Language::German));

        self::assertStringStartsWith('<!DOCTYPE html>', $markup);
        self::assertStringContainsString('lang="de"', $markup);
        self::assertStringContainsString('<title>Guide</title>', $markup);
        self::assertStringContainsString('<p>hello</p>', $markup);
    }

    /**
     * The status is asked, because a file on a static host is always a 200.
     *
     * @return void
     */
    public function testAViewResponseAnswersItsStatus(): void
    {
        self::assertSame(HttpStatusCode::Ok, new ViewResponse(self::view())->status());
        self::assertSame(
            HttpStatusCode::NotFound,
            new ViewResponse(self::view(), HttpStatusCode::NotFound)->status(),
        );
    }

    /**
     * @return View
     */
    private static function view(): View
    {
        return new class () extends View {
            /**
             * @return Translatable
             */
            public function pageTitle(): Translatable
            {
                return new Verbatim('Guide');
            }

            /**
             * @return Node
             */
            public function content(): Node
            {
                return new Element(HtmlTag::P)->containing('hello');
            }
        };
    }
}
