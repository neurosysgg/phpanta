<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Controller\Controller;
use Phpanta\Exception\InputException;
use Phpanta\Exception\TooLargeException;
use Phpanta\Http\FormEncoding;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Input;
use Phpanta\Http\MultipartParameters;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ServerParameters;
use Phpanta\Http\ServerVariable;
use Phpanta\Router;
use Phpanta\Support\Collection;
use Phpanta\Support\Route;
use Phpanta\Test\SourceTree;
use Phpanta\Test\TestRequest;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Language;
use PhpToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a request sent, read by parameter and by type: absent is null, wrong is a 400 — and the API
 * reads none of it.
 */
#[CoversClass(Input::class)]
#[CoversClass(MultipartParameters::class)]
#[CoversClass(Request::class)]
#[CoversClass(Router::class)]
#[CoversClass(ServerVariable::class)]
final class InputTest extends TestCase
{
    // ───────────────────────── the encoding ─────────────────────────

    /**
     * A query is `&`-separated pairs, `+` a space and `%xx` a byte — and a name is exactly what was
     * sent, brackets included.
     *
     * @param string           $query
     * @param ParameterFixture $parameter
     * @param string|null      $expected
     * @return void
     */
    #[DataProvider('encodingProvider')]
    public function testAQueryIsReadAsFormEncodedPairs(
        string $query,
        ParameterFixture $parameter,
        ?string $expected,
    ): void {
        self::assertSame($expected, self::query($query)->text($parameter));
    }

    /**
     * @return iterable<string, array{string, ParameterFixture, string|null}>
     */
    public static function encodingProvider(): iterable
    {
        yield 'a plus and an escape'  => ['q=drum+%26+bass', ParameterFixture::Term, 'drum & bass'];
        yield 'an empty value'        => ['q=', ParameterFixture::Term, ''];
        yield 'no equals sign'        => ['q', ParameterFixture::Term, ''];
        yield 'brackets are a name'   => ['a[]=1', ParameterFixture::Bracketed, '1'];
        yield 'empty pairs'           => ['&&q=x&', ParameterFixture::Term, 'x'];
        yield 'UTF-8'                 => ['q=caf%C3%A9', ParameterFixture::Term, 'café'];
        yield 'nothing sent'          => ['', ParameterFixture::Term, null];
        yield 'something else sent'   => ['page=1', ParameterFixture::Term, null];
        yield 'an equals in a value'  => ['q=a=b', ParameterFixture::Term, 'a=b'];
    }

    /**
     * Bytes that are not UTF-8 are refused outright.
     *
     * @return void
     */
    public function testWhatDoesNotDecodeToUtf8IsRefused(): void
    {
        $this->expectException(InputException::class);

        (void) self::query('q=%FF');
    }

    /**
     * A name sent twice is refused when it is read — which of the two was meant is not a guess
     * worth making — and every other name is still read.
     *
     * @return void
     */
    public function testANameSentTwiceIsRefusedWhenItIsRead(): void
    {
        $input = self::query('q=a&q=b&page=2');

        self::assertSame(2, $input->int(ParameterFixture::Page));
        self::assertTrue($input->has(ParameterFixture::Term));

        $this->expectException(InputException::class);
        $this->expectExceptionMessage("'q' was sent more than once.");

        (void) $input->text(ParameterFixture::Term);
    }

    // ───────────────────────── the types ─────────────────────────

    /**
     * @param string $value
     * @param int    $expected
     * @return void
     */
    #[DataProvider('intProvider')]
    public function testAWholeNumberIsReadAsAnInt(string $value, int $expected): void
    {
        self::assertSame($expected, self::query("page=$value")->int(ParameterFixture::Page));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function intProvider(): iterable
    {
        yield 'positive'        => ['42', 42];
        yield 'negative'        => ['-3', -3];
        yield 'zero'            => ['0', 0];
        yield 'eighteen digits' => [str_repeat('9', 18), (int) str_repeat('9', 18)];
    }

    /**
     * @param string $value
     * @return void
     */
    #[DataProvider('notIntProvider')]
    public function testAnythingElseSentAsAnIntIsRefused(string $value): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage("'page' is not a whole number.");

        (void) self::query("page=$value")->int(ParameterFixture::Page);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notIntProvider(): iterable
    {
        yield 'a word'          => ['banana'];
        yield 'a leading zero'  => ['007'];
        yield 'a fraction'      => ['1.5'];
        yield 'a plus sign'     => ['%2B1'];
        yield 'empty'           => [''];
        yield 'nineteen digits' => [str_repeat('9', 19)];
    }

    /**
     * An int not sent is null, for the page's default.
     *
     * @return void
     */
    public function testAnIntNotSentIsNull(): void
    {
        self::assertNull(self::query('')->int(ParameterFixture::Page));
    }

    /**
     * @param string $query
     * @param bool   $expected
     * @return void
     */
    #[DataProvider('flagProvider')]
    public function testAFlagIsAYesOrANo(string $query, bool $expected): void
    {
        self::assertSame($expected, self::query($query)->flag(ParameterFixture::Loud));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function flagProvider(): iterable
    {
        foreach (['1', 'on', 'true', 'yes', 'ON'] as $yes) {
            yield "yes as $yes" => ["loud=$yes", true];
        }

        foreach (['0', 'off', 'false', 'no', ''] as $no) {
            yield "no as '$no'" => ["loud=$no", false];
        }

        yield 'not sent' => ['', false];
    }

    /**
     * @return void
     */
    public function testAFlagSentAsNeitherIsRefused(): void
    {
        $this->expectException(InputException::class);

        (void) self::query('loud=maybe')->flag(ParameterFixture::Loud);
    }

    /**
     * A choice is one of an enum's cases by its value, null when not sent, and refused otherwise.
     *
     * @return void
     */
    public function testAChoiceIsOneOfItsCases(): void
    {
        self::assertSame(Language::German, self::query('lang=de')->choice(ParameterFixture::Language, Language::class));
        self::assertNull(self::query('')->choice(ParameterFixture::Language, Language::class));

        $this->expectException(InputException::class);

        (void) self::query('lang=xx')->choice(ParameterFixture::Language, Language::class);
    }

    /**
     * A page that says which parameters it reads refuses a request that sent another.
     *
     * @return void
     */
    public function testOnlyRefusesANameTheAddressDoesNotRead(): void
    {
        $known = self::query('page=1&q=x');

        self::assertSame($known, $known->only(ParameterFixture::class));

        $this->expectException(InputException::class);
        $this->expectExceptionMessage("'evil' is not a parameter this address reads.");

        (void) self::query('page=1&evil=1')->only(ParameterFixture::class);
    }

    // ───────────────────────── the form ─────────────────────────

    /**
     * A form is read from a body its sender calls url-encoded, whatever charset it names.
     *
     * @return void
     */
    public function testAFormIsReadFromAUrlEncodedBody(): void
    {
        $form = self::posted('application/x-www-form-urlencoded; charset=UTF-8', 'q=hello+there&page=3')->form();

        self::assertSame('hello there', $form->text(ParameterFixture::Term));
        self::assertSame(3, $form->int(ParameterFixture::Page));
    }

    /**
     * A request that says nothing about its body has sent no form.
     *
     * @return void
     */
    public function testARequestWithNoBodyTypeSentNoForm(): void
    {
        self::assertFalse(TestRequest::get('/')->request()->form()->has(ParameterFixture::Term));
    }

    /**
     * A body of any other kind is refused rather than half-read.
     *
     * @return void
     */
    public function testABodyOfAnotherKindIsRefused(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage("A body sent as 'text/plain' is not a form this reads.");

        (void) self::posted('text/plain', 'q=1')->form();
    }

    /**
     * A multipart form — how a form that sends a file sends everything else — is read from what PHP
     * parsed it into, by the same readers.
     *
     * @return void
     */
    public function testAMultipartFormIsReadFromWhatPhpParsed(): void
    {
        $input = TestRequest::to(HttpMethod::Post, '/')
            ->withField(ParameterFixture::Term, 'drum & bass')
            ->withField(ParameterFixture::Page, '2')
            ->request()
            ->form();

        self::assertSame('drum & bass', $input->text(ParameterFixture::Term));
        self::assertSame(2, $input->int(ParameterFixture::Page));
        self::assertFalse($input->has(ParameterFixture::Bracketed));
    }

    /**
     * A name PHP handed over as a list was sent more than once, and is refused when it is read — the
     * rest of the form still reads.
     *
     * @return void
     */
    public function testANamePostedAsAListIsRefusedWhenRead(): void
    {
        $input = self::multipart(['q' => ['a', 'b'], 'page' => '2'])->form();

        self::assertSame(2, $input->int(ParameterFixture::Page));

        $this->expectException(InputException::class);
        $this->expectExceptionMessage("'q' was sent more than once.");

        (void) $input->text(ParameterFixture::Term);
    }

    /**
     * A posted name or value that is not UTF-8 is refused outright, as a url-encoded one is.
     *
     * @param array<array-key, mixed> $fields
     * @return void
     */
    #[DataProvider('notUtf8Provider')]
    public function testAPostedNameOrValueThatIsNotUtf8IsRefused(array $fields): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Something sent does not decode to UTF-8.');

        (void) self::multipart($fields)->form();
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function notUtf8Provider(): iterable
    {
        yield 'a name'  => [["\xFF" => 'x']];
        yield 'a value' => [['q' => "\xFF"]];
    }

    /**
     * PHP's parse of a multipart body is read in one place. Anything else that named `$_POST` or
     * `$_FILES` would be a second reader of a map nothing types, and would read nothing at all in a
     * test, which builds its request without either.
     *
     * @return void
     */
    public function testOnlyTheDoorReadsPhpsParseOfAForm(): void
    {
        $readers = [];

        foreach (SourceTree::framework()->classes() as $path => $class) {
            foreach (PhpToken::tokenize((string) file_get_contents($path)) as $token) {
                if ($token->is(T_VARIABLE) && in_array($token->text, ['$_POST', '$_FILES'], true)) {
                    $readers[$class] = $class;
                }
            }
        }

        self::assertSame([MultipartParameters::class], array_values($readers));
    }

    /**
     * A form larger than the bound is refused rather than cut — as too large, a 413, the answer a
     * multipart form over `post_max_size` gets, rather than as unreadable.
     *
     * @return void
     */
    public function testAFormLargerThanTheBoundIsRefused(): void
    {
        $this->expectException(TooLargeException::class);

        (void) self::posted('application/x-www-form-urlencoded', 'q=' . str_repeat('a', Request::MAX_FORM))->form();
    }

    // ───────────────────────── the 400 ─────────────────────────

    /**
     * A page that reads a parameter sent unreadably is answered with a 400 by the router — the page
     * never checks — and one sent readably is answered by the page.
     *
     * @return void
     */
    public function testTheRouterAnswersUnreadableInputWithA400(): void
    {
        $router = new Router(new Collection(Route::class)->with(
            new Route(ExportFixturePath::Home, static fn(): Controller => new class () implements Controller {
                /**
                 * @param Request $request
                 * @return Response
                 */
                public function handle(Request $request): Response
                {
                    return new PlainTextResponse(
                        HttpStatusCode::Ok,
                        (string) ($request->query()->int(ParameterFixture::Page) ?? 1),
                    );
                }
            }),
        ));

        $read    = TestRequest::get('/?page=2')->request();
        $refused = TestRequest::get('/?page=banana')->request();

        self::assertSame('2', $router->handle($read)->answer($read)->body());

        $answer = $router->handle($refused)->answer($refused);

        self::assertSame(HttpStatusCode::BadRequest, $answer->status());
        self::assertSame(FrameworkText::BadRequest->in(Language::English) . "\n", $answer->body());
        self::assertStringNotContainsString('banana', $answer->body());
    }

    // ───────────────────────── the API reads none of it ─────────────────────────

    /**
     * Everything an API action may act on is signed, and a query parameter or a form field would
     * reach it unsigned — so no line of the API's code asks a request for either. Read, not trusted.
     *
     * @return void
     */
    public function testNoApiCodeReadsTheQueryOrAForm(): void
    {
        $files = [
            PHPANTA_ROOT . '/src/Controller/ApiController.php',
            PHPANTA_ROOT . '/src/Service/ApiGate.php',
            ...glob(PHPANTA_ROOT . '/src/Service/Api/*.php') ?: [],
            ...glob(PHPANTA_ROOT . '/src/Http/Api/*.php') ?: [],
            ...glob(PHPANTA_ROOT . '/src/Model/Api/*.php') ?: [],
        ];

        self::assertGreaterThan(10, count($files), 'the API was not found where this looks for it');

        $reads = [];

        foreach ($files as $file) {
            $tokens = PhpToken::tokenize((string) file_get_contents($file));

            foreach ($tokens as $index => $token) {
                if (!$token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                    continue;
                }

                $next = $tokens[$index + 1] ?? null;

                if ($next !== null && in_array($next->text, ['query', 'form'], true)) {
                    $reads[] = basename($file) . ':' . $token->line;
                }
            }
        }

        self::assertSame([], $reads, 'an API file reads a query parameter or a form field');
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * @param string $query
     * @return Input
     */
    private static function query(string $query): Input
    {
        return TestRequest::get('/?' . $query)->request()->query();
    }

    /**
     * A POST carrying $body, said to be $type.
     *
     * @param string $type
     * @param string $body
     * @return Request
     */
    private static function posted(string $type, string $body): Request
    {
        return TestRequest::to(HttpMethod::Post, '/')
            ->withServer(ServerVariable::ContentType, $type)
            ->withBody($body)
            ->request();
    }

    /**
     * A multipart POST whose fields PHP parsed into $fields — shaped as `$_POST` may be, lists and
     * all, which {@link TestRequest::withField()} cannot send.
     *
     * @param array<array-key, mixed> $fields
     * @return Request
     */
    private static function multipart(array $fields): Request
    {
        return Request::from(
            new ServerParameters([ServerVariable::ContentType->value => FormEncoding::Multipart->value]),
            null,
            new MultipartParameters($fields, []),
        );
    }
}
