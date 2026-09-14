<?php

declare(strict_types=1);

namespace Hello\Test;

use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\SecurityHeader;
use Phpanta\Test\TestRequest;
use PHPUnit\Framework\TestCase;

/**
 * What the example answers, asked in-process: the whole request but the process around it.
 */
final class HelloTest extends TestCase
{
    /**
     * The world is greeted in the visitor's language, and offered someone to greet next.
     *
     * @return void
     */
    public function testTheWorldIsGreetedInTheVisitorsLanguage(): void
    {
        $english = TestRequest::get('/')->answer();
        $german  = TestRequest::get('/')->with(RequestHeader::AcceptLanguage, 'de-AT, en;q=0.5')->answer();

        self::assertSame(HttpStatusCode::Ok, $english->status());
        self::assertStringContainsString('<h1>Hello, world!</h1>', $english->body());
        self::assertStringContainsString('<a href="/hello/Ada">Ada</a>', $english->body());
        self::assertStringContainsString('<html lang="de">', $german->body());
        self::assertStringContainsString('<h1>Hallo, Welt!</h1>', $german->body());
        self::assertStringContainsString('Jetzt grüß <a href="/hello/Ada">Ada</a>.', $german->body());
    }

    /**
     * A name is greeted, and its letters counted by each language's own plural rules.
     *
     * @return void
     */
    public function testANameIsGreetedAndCountedByTheLanguagesOwnRules(): void
    {
        $ada = TestRequest::get('/hello/Ada')->answer()->body();
        $one = TestRequest::get('/hello/%C3%85')->with(RequestHeader::AcceptLanguage, 'de')->answer()->body();

        self::assertStringContainsString('<title>Ada — Hello</title>', $ada);
        self::assertStringContainsString('<h1>Hello, Ada!</h1>', $ada);
        self::assertStringContainsString('Your name has 3 letters.', $ada);
        self::assertStringContainsString('<h1>Hallo, Å!</h1>', $one);
        self::assertStringContainsString('Dein Name hat einen Buchstaben.', $one);
    }

    /**
     * A name with markup in it is text on the page, because nothing here writes markup from a string.
     *
     * @return void
     */
    public function testANameIsOnlyEverText(): void
    {
        $body = TestRequest::get('/hello/%3Cscript%3Ealert(1)%3C%2Fscript%3E')->answer()->body();

        self::assertStringContainsString('Hello, &lt;script&gt;alert(1)&lt;/script&gt;!', $body);
        self::assertStringNotContainsString('<script>', $body);
    }

    /**
     * What the example never wrote a line for: a 404 in the visitor's language, a 405 for a write,
     * the security headers, and the admin.
     *
     * @return void
     */
    public function testWhatComesWithoutALineOfItsOwn(): void
    {
        $nowhere = TestRequest::get('/nowhere')->with(RequestHeader::AcceptLanguage, 'de')->answer();
        $write   = TestRequest::to(HttpMethod::Post, '/')->answer();
        $admin   = TestRequest::get('/admin')->answer();

        self::assertSame(HttpStatusCode::NotFound, $nowhere->status());
        self::assertSame("Hier wohnt niemand.\n", $nowhere->body());
        self::assertSame(HttpStatusCode::MethodNotAllowed, $write->status());
        self::assertNotNull($write->header(SecurityHeader::ContentSecurityPolicy));
        self::assertSame(HttpStatusCode::Ok, $admin->status());
        self::assertStringContainsString('<title>', $admin->body());
    }
}
