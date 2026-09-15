<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use InvalidArgumentException;
use Phpanta\Support\BareArray;
use Phpanta\Support\BareCall;
use Phpanta\Support\BareString;
use Phpanta\Test\SourceTree;
use PhpToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * The habits the framework is built against, checked by asking PHP about itself.
 *
 * Everything under `src/` is an argument against two shapes. A **bare array** announces nothing
 * about what it holds, which is why {@link \Phpanta\Support\Collection} exists; a **bare string**
 * is a name with no vocabulary, which is why the names and values the framework handles are enums.
 * Both arguments were made one class at a time and neither had anything watching it, so the only
 * thing standing between the code and a slow return to arrays-and-strings was whoever wrote the next
 * method.
 *
 * This is that thing. It reads the code the way PHP does — reflection for what is *declared*, the
 * tokenizer for what is *written* — and it wants an argument for every exception rather than
 * silence. An exception is an attribute with a sentence in it: {@link BareArray},
 * {@link BareString}, {@link BareCall}. The lists below are what those sentences are attached to,
 * pinned so the set cannot grow without somebody noticing, in the same spirit as
 * {@link NoDiscardTest::testExactlyTheseResultsMayNotBeDiscarded()}.
 *
 * **Every rule is checked in both directions.** An unexcused violation fails, and so does an excuse
 * for something that is no longer a violation — an attribute left behind after the array became a
 * collection is a sentence describing code that is not there, and the next reader will believe it.
 *
 * The same rules hold a site built on the framework: its suite runs them over its own tree, with
 * the framework beside it where a rule asks a question of the whole program, and pins its own
 * excuses there. What is pinned here is the framework's half, stated without any site.
 *
 * Smaller guidelines ride along at the bottom, at zero and here as regressions rather than as work:
 * `strict_types` on every file, a declared type on every declaration, a backing value on every enum,
 * a `@throws` on every throw, a named class in every catch. Each is invisible when it slips.
 *
 * **What is deliberately not checked.** `mixed` appears under `src/` as a collection's element type,
 * and it is there because PHP has no generics rather than because anybody chose it; a fourth
 * attribute for a set that cannot change would be ceremony. `tools/lib/` is out of the *declaration*
 * rules for the same kind of reason — it is not deployed, and the doors it is made of are most of
 * what it does. {@link self::testNothingSuppressesADiagnosticWithAnAtSign()} is the one rule that does
 * walk it, and says on itself why a suppression is not a door.
 *
 * It covers the three attributes and nothing else, which is not the `#[CoversNothing]`
 * {@link NoDiscardTest} carries and is the honest difference between them: that test only ever
 * *asks* reflection what an attribute says, where this one also constructs each of these and proves
 * each refuses a hole. Everything else here executes no line of `src/` at all.
 */
#[CoversClass(BareArray::class)]
#[CoversClass(BareCall::class)]
#[CoversClass(BareString::class)]
final class GuidelineTest extends TestCase
{
    /**
     * The array functions a {@link \Phpanta\Support\Collection} answers, and the member that
     * answers each.
     *
     * This table *is* the rule. A function on it is one the collections can do, so a call to it
     * under `src/` needs either the member or a `#[BareCall]`; a function not on it — `array_slice`,
     * `array_shift`, `array_merge`, `array_any`, `array_key_last` — is outside the rule entirely,
     * because demanding an excuse for a call with no replacement asks for an apology rather than an
     * argument. Writing a member is what adds a row, and adding a row is what makes every existing
     * call to that function fail until somebody looks at it.
     *
     * Named here rather than read off the collections by reflection: a test that derives its
     * expectation from the thing it tests asserts nothing. The pairing is also not mechanical —
     * `array_find` is `first()` and `array_filter` is `where()`, and no amount of reflection would
     * guess either.
     */
    private const array COLLECTION_MEMBERS = [
        'array_filter' => 'where()',
        'array_find'   => 'first()',
        'array_keys'   => 'toKeys()',
        'array_map'    => 'map()',
        'array_unique' => 'unique()',
        'array_values' => 'toValues()',
    ];

    /**
     * The three files the call rule does not read.
     *
     * Exempt outright, the way an enum declaration is exempt from the string rule and for the same
     * reason: this is where the thing lives. `Collection`, `SearchableCollection` and `TypedItems`
     * *are* the members in the table above, and the array functions are what they are made of —
     * `toValues()` is `array_values()`, and asking it to call itself is not a rule, it is a loop.
     */
    private const array COLLECTION_FILES = ['Collection.php', 'SearchableCollection.php', 'TypedItems.php'];

    /**
     * Every `array` in a declared type under `src/` carries an excuse.
     *
     * Parameters, returns, properties and typed constants, reflected rather than grepped: the engine
     * is what knows what a signature says, and a docblock's `list<Element>` is exactly the promise
     * this rule exists to stop trusting. And closures, read from the tokens — reflection reaches a
     * closure only once it exists — each excused by a `#[BareArray]` written on it.
     *
     * A **variadic** is not on the list and never will be. `deny(PermissionsPolicyFeature
     * ...$features)` is a check PHP makes for free, and a collection parameter there would replace
     * it with one we make ourselves — the distinction docs/collections.md draws between what a class
     * *takes* and what it *stores*.
     *
     * @return void
     */
    public function testEveryBareArrayIsExcused(): void
    {
        self::assertSame(
            [],
            self::bareArrays()['unexcused'],
            'an array in a declared type with no #[BareArray] saying why it is not a Collection',
        );
    }

    /**
     * These, and only these, stay arrays.
     *
     * Four kinds, and it is worth knowing which is which before adding a fifth:
     *
     * - **A door.** `preg_match`'s matches in `Route::matches()`, `file()`'s lines, a directory's
     *   entries, `toArray()` itself. PHP hands these over as arrays and no amount of typing on this
     *   side changes that; the collection is what crosses the boundary, and the door is where it is
     *   built.
     * - **A variadic's argument.** `varyOn()` is spread into a call PHP already guards.
     * - **A tuple.** `entry()` and `credentials()` return two values of different kinds in a fixed
     *   order, which is the one shape a homogeneous collection cannot hold.
     * - **An accumulator.** `$qualities` is written to in a loop, where `with()` would copy.
     *
     * A closure is named by the method it is written in and a count — `Class::method()_1` is the
     * first closure there that names an array — and a typed constant by its own name.
     *
     * The collections' own members appear three times each. That is this test working and not
     * failing, for the reason {@link NoDiscardTest} states about the same trait: PHP flattens a
     * trait's members into each using class, and counting one twice is the direction a test like
     * this can survive.
     *
     * @return void
     */
    public function testExactlyTheseArraysStayBare(): void
    {
        self::assertSame(
            [
                'Phpanta\App::adminRoutes()_1',
                'Phpanta\Data\Row::$values',
                'Phpanta\Data\Row::__construct()',
                'Phpanta\Data\Sql::__construct()_1',
                'Phpanta\Http\AcceptedLanguages::$qualities',
                'Phpanta\Http\AcceptedLanguages::__construct()',
                'Phpanta\Http\AcceptedLanguages::entry()',
                'Phpanta\Http\Api\ApiService::machineActions()',
                'Phpanta\Http\AuthScheme::credentials()',
                'Phpanta\Http\BasicChallenge::encoding()_1',
                'Phpanta\Http\MultipartParameters::$fields',
                'Phpanta\Http\MultipartParameters::$files',
                'Phpanta\Http\MultipartParameters::__construct()',
                'Phpanta\Http\MultipartParameters::kept()',
                'Phpanta\Http\MultipartParameters::uploaded()',
                'Phpanta\Http\SecurityHeaders::headers()',
                'Phpanta\Http\Security\ContentSecurityPolicy::hosts()',
                'Phpanta\Http\ServerParameters::$values',
                'Phpanta\Http\ServerParameters::__construct()',
                'Phpanta\Model\Machine\MachineEntry::at()_1',
                'Phpanta\Model\Machine\MachineEntry::in()_1',
                'Phpanta\Service\FilesystemProbe::device()_1',
                'Phpanta\Service\FilesystemProbe::strays()_1',
                'Phpanta\Service\Machine\LinuxProbe::entries()',
                'Phpanta\Service\Machine\LinuxProbe::entries()_1',
                'Phpanta\Service\Machine\LinuxProbe::traffic()',
                'Phpanta\Service\Machine\StreamMode::pipe()',
                'Phpanta\Service\UpdateApplier::directories()',
                'Phpanta\Service\UpdateApplier::entries()',
                'Phpanta\Service\UpdateApplier::entries()_1',
                'Phpanta\Service\UpdateApplier::surplusIn()',
                'Phpanta\Service\UpdateApplier::walk()',
                'Phpanta\Support\Collection::$items',
                'Phpanta\Support\Collection::$steps',
                'Phpanta\Support\Collection::SCALARS',
                'Phpanta\Support\Collection::stringKeyed()',
                'Phpanta\Support\Collection::toArray()',
                'Phpanta\Support\Collection::toKeys()',
                'Phpanta\Support\Collection::toValues()',
                'Phpanta\Support\Directory::entries()',
                'Phpanta\Support\Directory::entries()_1',
                'Phpanta\Support\File::lines()',
                'Phpanta\Support\File::lines()_1',
                'Phpanta\Support\FillsPlaceholders::to()_1',
                'Phpanta\Support\Route::createController()',
                'Phpanta\Support\Route::matches()',
                'Phpanta\Support\SearchableCollection::$items',
                'Phpanta\Support\SearchableCollection::$steps',
                'Phpanta\Support\SearchableCollection::SCALARS',
                'Phpanta\Support\SearchableCollection::stringKeyed()',
                'Phpanta\Support\SearchableCollection::toArray()',
                'Phpanta\Support\SearchableCollection::toKeys()',
                'Phpanta\Support\SearchableCollection::toValues()',
                'Phpanta\Support\TarArchive::header()',
                'Phpanta\Support\TarArchive::name()',
                'Phpanta\Support\Throttle::times()',
                'Phpanta\Support\TypedItems::$items',
                'Phpanta\Support\TypedItems::$steps',
                'Phpanta\Support\TypedItems::SCALARS',
                'Phpanta\Support\TypedItems::stringKeyed()',
                'Phpanta\Support\TypedItems::toArray()',
                'Phpanta\Support\TypedItems::toKeys()',
                'Phpanta\Support\TypedItems::toValues()',
                'Phpanta\Text\Phrase::$arguments',
                'Phpanta\Text\Phrase::__construct()',
                'Phpanta\View\AdminEnrolmentView::varyOn()',
                'Phpanta\View\AdminEntranceView::varyOn()',
                'Phpanta\View\ApiActionFormView::varyOn()',
                'Phpanta\View\ApiListingView::varyOn()',
                'Phpanta\View\ApiResultView::varyOn()',
                'Phpanta\View\Html\Element::URL_SCHEMES',
                'Phpanta\View\View::varyOn()',
            ],
            array_keys(self::bareArrays()['excused']),
        );
    }

    /**
     * Every string literal under `src/` that has a name already carries an excuse.
     *
     * The rule is not "no literals". A heading and an exception message are both text and neither
     * is a name; requiring an argument for each would produce hundreds of arguments and nobody would
     * read the few that mattered. It catches the two shapes where a literal genuinely is a
     * vocabulary written out:
     *
     * - **A word an enum in reach already spells.** If the file names {@link
     *   \Phpanta\Http\HttpMethod} anywhere, `'GET'` in it is that enum's case as text. This is the
     *   clause that found a real drift — `Request::fromGlobals()` defaulted to a `'GET'` that
     *   `HttpMethod::Get` had spelled all along.
     * - **A word written in two classes.** One occurrence is a value; the same one in another file
     *   is a fact with two spellings and nothing keeping them in step. Two *files* rather than two
     *   lines, deliberately: a literal repeated inside one class is on one screen and has `const`
     *   waiting for it, where the failures this code is written against are always the two halves
     *   of something in two files, neither knowing about the other.
     *
     * Enum declarations are exempt outright — that is where a vocabulary is supposed to live — and
     * so is anything with no letter or digit in it. `'/'`, `', '` and `"\n"` are structure, and a
     * name for them would read worse than they do.
     *
     * An attribute's own arguments are exempt too, which is not a special case so much as the same
     * one: a reason is prose about the code, like a docblock, and the attributes below were caught
     * spelling half a sentence identically before that was true.
     *
     * @return void
     */
    public function testEveryBareStringIsExcused(): void
    {
        self::assertSame(
            [],
            self::bareStrings()['unexcused'],
            'a literal that a vocabulary already spells, with no #[BareString] saying otherwise',
        );
    }

    /**
     * These, and only these, stay literals.
     *
     * Every one of them is a coincidence rather than a shortcut — which is the point of listing
     * them: each is a word that *looks* like a name and is not. They are all **someone else's
     * vocabulary**: `string` is `get_debug_type()`'s spelling, in a class-string's place, and a
     * `string` written by six classes is six of them asking PHP the same question.
     *
     * @return void
     */
    public function testExactlyTheseStringsStayBare(): void
    {
        self::assertSame(
            [
                'Phpanta\Data\Migrations string',
                'Phpanta\Http\Input string',
                'Phpanta\Http\MultipartParameters string',
                'Phpanta\Http\Session string',
                'Phpanta\Model\Health\HealthSection string',
                'Phpanta\Model\Update\UpdateReport string',
                'Phpanta\Support\Diagnostics string',
                'Phpanta\Support\Route string',
                'Phpanta\Support\TypedItems string',
                'Phpanta\View\Html\Vocabulary string',
            ],
            array_keys(self::bareStrings()['excused']),
        );
    }

    /**
     * These excuses answer for a word the framework writes once and a site writes again.
     *
     * Read against the framework alone, each is an excuse for a literal that is not bare — the one
     * shape {@link self::testNoExcuseOutlivesWhatItExcused()} refuses — because the second writer
     * is not here. It is in a site: the rule's second clause is a question about the whole program,
     * and a program is a site with the framework inside it. So the framework carries its half of the
     * argument where it writes the word, and the site's suite, which reads both trees, holds the
     * other half.
     *
     * - **Another grammar.** `#^https://…#i` is the regex {@link \Phpanta\Http\Location} checks a
     *   redirect with, and a site may keep the same pattern for the addresses its data carries —
     *   genuinely one fact in two files, kept apart on purpose, and argued where it is written.
     * - **Someone else's vocabulary.** `c` is `fopen()`'s mode in {@link \Phpanta\Support\FileLock},
     *   and a one-letter word in `date()`'s formats too; `int` is `get_debug_type()`'s spelling in
     *   {@link \Phpanta\Support\TypedItems}, which a site asking PHP the same question writes too.
     *
     * Pinned exactly, so an excuse that stops answering for anything still fails — it has to be
     * taken off this list first, by somebody who looked.
     *
     * @return void
     */
    public function testExactlyTheseExcusesAnswerForAWordASiteWritesToo(): void
    {
        self::assertSame(
            [
                'Phpanta\Http\Location \'#^https://[^\\\\s/]+(?:[/?\\\\#]\\\\S*)?\\\\z#i\'',
                'Phpanta\Support\FileLock \'c\'',
                'Phpanta\Support\TypedItems \'int\'',
            ],
            self::bareStrings()['stale'],
        );
    }

    /**
     * Every call to an array function a collection already answers carries an excuse.
     *
     * The tokenizer for the call and reflection for the method it sits in, which is the smallest
     * thing an attribute can hang on — a line number is not something `#[BareCall]` could name. A
     * call inside a closure counts as the enclosing method's, which is what you want: the closure
     * is written there.
     *
     * See {@link self::COLLECTION_MEMBERS} for what is asked about and what deliberately is not.
     *
     * @return void
     */
    public function testEveryBareCallIsExcused(): void
    {
        self::assertSame(
            [],
            self::bareCalls()['unexcused'],
            'an array function a Collection has a member for, with no #[BareCall] saying otherwise',
        );
    }

    /**
     * These, and only these, stay calls.
     *
     * Two kinds, and both are about what is on the other side of the call rather than about the
     * call:
     *
     * - **A class constant.** `Element::verifyUrl()` maps over one, and a class constant *cannot*
     *   hold a `Collection` — `new` is not a constant expression, so `Element::URL_SCHEMES` is an
     *   array wherever it is read. This one is permanent until PHP says otherwise.
     * - **A door, or a variadic straight through one.** `File::lines()` is `file()`'s doorway, and
     *   `Element::containing()` maps the variadic PHP has already guarded directly into `with()` —
     *   a collection there would be built only to be spread back out on the same line, on the
     *   hottest path a page has.
     *
     * @return void
     */
    public function testExactlyTheseCallsStayBare(): void
    {
        self::assertSame(
            [
                'Phpanta\Support\File::lines array_values',
                'Phpanta\View\Html\Element::containing array_map',
                'Phpanta\View\Html\Element::verifyUrl array_map',
            ],
            array_keys(self::bareCalls()['excused']),
        );
    }

    /**
     * No excuse outlives the thing it excused.
     *
     * This is the direction that keeps the lists above honest rather than merely long. A
     * `#[BareArray]` on a method that now returns a collection, or a `#[BareCall]` for a call the
     * method no longer makes, is a sentence about code that is not there — and it reads as true,
     * because it did use to be. The strings' half is
     * {@link self::testExactlyTheseExcusesAnswerForAWordASiteWritesToo()}, which pins the two a
     * site's second copy answers for.
     *
     * @return void
     */
    public function testNoExcuseOutlivesWhatItExcused(): void
    {
        self::assertSame([], self::bareArrays()['stale'], '#[BareArray] on something that is not one');
        self::assertSame([], self::bareCalls()['stale'], '#[BareCall] for a call that is not made');
    }

    /**
     * An excuse with a hole in it is refused where it is written.
     *
     * The lists above report a fault against a set; the constructors report it against the line
     * that is wrong. A bare `#[BareArray]` would say the array is deliberate — which the reader
     * already suspected — where what is worth saying is which door it is.
     *
     * @return void
     */
    public function testAnExcuseMustExplainItself(): void
    {
        $holes = [
            'an array excused without a reason'  => static fn(): object => new BareArray(''),
            'a literal excused without a reason' => static fn(): object => new BareString('x', ''),
            'a reason attached to no literal'    => static fn(): object => new BareString('', 'why'),
            'a call excused without a reason'    => static fn(): object => new BareCall('array_map', ''),
            'an excuse for no such function'     => static fn(): object => new BareCall('array_nope', 'why'),
        ];

        foreach ($holes as $what => $construct) {
            try {
                $construct();
                self::fail($what . ' was accepted');
            } catch (InvalidArgumentException $refused) {
                self::assertNotSame('', $refused->getMessage(), $what . ' threw without saying what');
            }
        }
    }

    /**
     * Nothing suppresses a diagnostic with an `@`.
     *
     * The one rule here with no excuse mechanism at all, because the replacement is strictly better
     * rather than merely tidier and there was nothing left to argue for.
     * {@link \Phpanta\Support\Diagnostics} is what every suppression became.
     *
     * `@` cannot say which diagnostics it meant: it silences every one raised anywhere in the
     * expression, at any severity, from any call nested inside it — so a `@file_get_contents()`
     * written for a missing file also swallows an `E_DEPRECATED` that arrives with a PHP upgrade,
     * and nothing says so. And it cannot answer for one call, because `error_get_last()` is
     * process-global and sticky, which is why `MarkupParser` had to hand-roll a handler to refuse a
     * policy document on a parse error.
     *
     * **This is the one rule that walks `tools/lib/` as well**, and the reason is the same one that
     * keeps that tree out of the others: what is excluded there are the *doors* — `unpack`,
     * `preg_match`, `file` — and a suppression is not a door. The signing side of the API holds the
     * only private key a deployment's owner ever hands it, which is not a place to leave a character
     * that hides whatever it happens to be in front of.
     *
     * The token is `@` on its own; `#[` is `T_ATTRIBUTE` and a docblock's `@param` is one
     * `T_DOC_COMMENT`, so neither is reachable from here. `test/` is deliberately outside: a
     * fixture's teardown unlinks paths it does not care about, which is the one place the blunt
     * instrument is the right one.
     *
     * @return void
     */
    public function testNothingSuppressesADiagnosticWithAnAtSign(): void
    {
        $suppressed = [];

        foreach (SourceTree::framework()->files(PHPANTA_ROOT . '/tools/lib') as $path) {
            foreach (PhpToken::tokenize(file_get_contents($path)) as $token) {
                if ($token->text === '@' && $token->id !== T_ATTRIBUTE) {
                    $suppressed[] = substr($path, strlen(PHPANTA_ROOT) + 1) . ':' . $token->line;
                }
            }
        }

        self::assertSame(
            [],
            $suppressed,
            '@ hides every diagnostic in the expression; Diagnostics::muted() names which it hides',
        );
    }

    /**
     * Every exception thrown under `src/` is one of ours.
     *
     * The rule with the shortest argument. An exception is a name for a condition, and a bare
     * `RuntimeException` names the condition "something", which is the same complaint the enums and
     * the collections answer one layer down. `Phpanta\Exception` is where the vocabulary lives, and
     * a throw that reaches outside it is a condition nobody has bothered to say the name of.
     *
     * **The two that look like exceptions to it are not.** {@link
     * \Phpanta\Exception\CollectionException} extends `TypeError` and {@link
     * \Phpanta\Exception\GuidelineException} extends `InvalidArgumentException` — so what a caller
     * catches is unchanged and every `expectException` still matches. What changed is only that the
     * throw says which layer raised it. Extending an SPL class is how you keep a promise; throwing
     * one is how you avoid making one.
     *
     * @return void
     */
    public function testEveryExceptionThrownUnderSrcIsOneOfOurs(): void
    {
        self::assertSame(
            [],
            self::throwsUnderSrc()['foreign'],
            'a throw naming an exception outside Phpanta\Exception, which names no condition',
        );
    }

    /**
     * Every method that throws says so.
     *
     * At zero when it was written, and here because it is invisible when it goes. An undeclared
     * `@throws` is not a wrong answer, it is a caller who was never asked the question — and the
     * callers of the framework are a site's views and controllers, whose whole job is to be
     * composed into something else.
     *
     * Only a *direct* throw is asked about. A method that propagates one from a callee is free to
     * declare it or not, which is a judgement — {@link \Phpanta\View\Html\Element::containingHtml()}
     * declares the base of two, deliberately — and a test that made that judgement mechanically
     * would be wrong more often than the people are.
     *
     * @return void
     */
    public function testEveryThrowUnderSrcIsDeclared(): void
    {
        self::assertSame(
            [],
            self::throwsUnderSrc()['undeclared'],
            'a method that throws with no @throws saying so',
        );
    }

    /**
     * Every `catch` names what it means to handle, and every wrap keeps what it caught.
     *
     * Two halves of one habit, both at zero.
     *
     * **A catch names a concrete class.** `catch (Throwable)` and `catch (Exception)` are the
     * `mixed` of error handling: they catch the condition you thought of and every one you did not,
     * and the second kind is then indistinguishable from the first. Note that `Exception` would not
     * even be the wide net it looks like here — {@link \Phpanta\Exception\CollectionException}
     * extends `Error`, which is why {@link \Phpanta\Exception\SiteException} exists.
     *
     * **One catch names everything, and it is pinned by name.** `App::run()` is the request's own
     * boundary: whatever `handle()` throws — a controller, a view, a shell — is answered there by
     * `App::fault()`, and above it is nothing but the front controller's last-resort handler. A named
     * class in that one place would let every unnamed fault through to the handler that can say the
     * least; catching everything is the point of it, so it is listed here rather than excused
     * nowhere, and a second one fails.
     *
     * **A wrap keeps its cause.** A `catch` that binds a variable and then throws must hand that
     * variable to the new exception, or the stack trace stops at the wrap and the actual failure —
     * which line of JSON, which byte — is gone.
     *
     * @return void
     */
    public function testEveryCatchNamesWhatItHandlesAndEveryWrapKeepsItsCause(): void
    {
        $catches = self::catchesUnderSrc();

        self::assertSame(
            ['Phpanta\App catches Throwable'],
            $catches['broad'],
            'a catch naming Throwable or Exception rather than a condition',
        );
        self::assertSame([], $catches['unwrapped'], 'a catch that throws without passing on what it caught');
    }

    /**
     * Every file under `src/` declares strict types.
     *
     * At zero, and here because the failure is silent in the worst way: without it a `string`
     * parameter accepts an `int` and coerces, so every type the framework spent its effort on
     * becomes a suggestion in exactly one file and nothing says so.
     *
     * @return void
     */
    public function testEveryFileUnderSrcDeclaresStrictTypes(): void
    {
        $missing = [];

        foreach (SourceTree::framework()->classes() as $path => $class) {
            $tokens = PhpToken::tokenize(file_get_contents($path));
            $strict = false;

            foreach ($tokens as $at => $token) {
                if ($token->id !== T_DECLARE) {
                    continue;
                }

                // The whole statement, because any other declare — ticks, an encoding — is not this.
                $declared = '';

                for ($k = $at; isset($tokens[$k]) && $tokens[$k]->text !== ';'; $k++) {
                    $declared .= $tokens[$k]->is(T_WHITESPACE) ? '' : $tokens[$k]->text;
                }

                $strict = strtolower($declared) === 'declare(strict_types=1)';
                break;
            }

            if (!$strict) {
                $missing[] = $class;
            }
        }

        self::assertSame([], $missing, 'declare(strict_types=1) is missing');
    }

    /**
     * Every declaration under `src/` says what it is.
     *
     * Parameters, returns and properties. `__construct` is excused its return type, which PHP does
     * not let it have; engine-synthesised members — an enum's `cases()`, `from()`, `tryFrom()` —
     * are not ours and are skipped by asking whether they came from a file.
     *
     * @return void
     */
    public function testEveryDeclarationUnderSrcCarriesAType(): void
    {
        $untyped = [];

        foreach (SourceTree::framework()->classes() as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || !$method->isUserDefined()) {
                    continue;
                }

                if (!$method->hasReturnType() && $method->getName() !== '__construct') {
                    $untyped[] = $class . '::' . $method->getName() . '() has no return type';
                }

                foreach ($method->getParameters() as $parameter) {
                    if (!$parameter->hasType()) {
                        $untyped[] = $class . '::' . $method->getName() . '($' . $parameter->getName() . ')';
                    }
                }
            }

            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() === $class && !$property->hasType()) {
                    $untyped[] = $class . '::$' . $property->getName();
                }
            }
        }

        self::assertSame([], $untyped, 'a declaration under src/ that states no type');
    }

    /**
     * Every enum under `src/` is backed.
     *
     * A pure enum has no wire form, and every vocabulary here has one — a header name, a tag, an
     * attribute, a path pattern. The one that would hurt most is a mirror: the TypeScript side of an
     * enum the client reads compares *values*, so a case with none is a parity test with nothing to
     * compare.
     *
     * @return void
     */
    public function testEveryEnumUnderSrcIsBacked(): void
    {
        $pure = [];

        foreach (SourceTree::framework()->classes() as $class) {
            if (enum_exists($class) && !new ReflectionEnum($class)->isBacked()) {
                $pure[] = $class;
            }
        }

        self::assertSame([], $pure, 'an enum under src/ with no backing value');
    }

    /**
     * Every `throw new` under `src/`, judged twice: is it ours, and did the method say so.
     *
     * The name is resolved the way PHP resolves it — against the file's `use` block, then against
     * its own namespace — because `throw new UpdateException` and `throw new RuntimeException` are
     * the same shape in the token stream and only the imports tell them apart.
     *
     * @return array{foreign: list<string>, undeclared: list<string>}
     */
    private static function throwsUnderSrc(): array
    {
        $foreign    = [];
        $undeclared = [];

        foreach (SourceTree::framework()->classes() as $path => $class) {
            $tokens  = PhpToken::tokenize(file_get_contents($path));
            $imports = self::imports($tokens);
            $methods = [];

            foreach (new ReflectionClass($class)->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() === $class && $method->isUserDefined()) {
                    $methods[$method->getName()] = [
                        $method->getStartLine(),
                        $method->getEndLine(),
                        $method->getDocComment() ?: '',
                    ];
                }
            }

            $namespace = substr($class, 0, (int) strrpos($class, '\\'));

            foreach (self::thrown($tokens) as [$name, $line]) {
                $resolved = $imports[$name] ?? ltrim($namespace . '\\' . $name, '\\');

                if (!str_starts_with($resolved, 'Phpanta\\Exception\\')) {
                    $foreign[] = $class . ':' . $line . ' throws ' . $resolved;
                }

                foreach ($methods as $method => [$from, $to, $doc]) {
                    if ($line < $from || $line > $to) {
                        continue;
                    }

                    if (!str_contains($doc, '@throws ' . $name)) {
                        $undeclared[] = $class . '::' . $method . '() throws ' . $name . ' and declares it nowhere';
                    }

                    break;
                }
            }
        }

        sort($foreign);
        sort($undeclared);

        return ['foreign' => $foreign, 'undeclared' => array_values(array_unique($undeclared))];
    }

    /**
     * Every `catch` under `src/`, judged twice: is it specific, and does a wrap keep its cause.
     *
     * A non-capturing `catch (X)` cannot wrap anything, so it is only asked the first question.
     * A capturing one is asked both, and "keeps its cause" means the caught variable appears inside
     * the argument list of whatever the block throws — which is where `previous` is, however it is
     * spelled.
     *
     * @return array{broad: list<string>, unwrapped: list<string>}
     */
    private static function catchesUnderSrc(): array
    {
        $broad     = [];
        $unwrapped = [];

        foreach (SourceTree::framework()->classes() as $path => $class) {
            $tokens = PhpToken::tokenize(file_get_contents($path));
            $count  = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                if ($tokens[$i]->id !== T_CATCH) {
                    continue;
                }

                $types    = [];
                $variable = null;

                for ($j = $i; $j < $count && $tokens[$j]->text !== '{'; $j++) {
                    if ($tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                        $types[] = ltrim($tokens[$j]->text, '\\');
                    } elseif ($tokens[$j]->id === T_VARIABLE) {
                        $variable = $tokens[$j]->text;
                    }
                }

                foreach ($types as $type) {
                    if ($type === 'Throwable' || $type === 'Exception') {
                        $broad[] = $class . ' catches ' . $type;
                    }
                }

                if ($variable === null) {
                    continue;
                }

                // Walk the block, and any `throw new` in it has to name the caught variable
                // somewhere in its arguments.
                for ($depth = 0, $k = $j; $k < $count; $k++) {
                    $depth += (int) ($tokens[$k]->text === '{') - (int) ($tokens[$k]->text === '}');

                    if ($depth === 0 && $k > $j) {
                        break;
                    }

                    if ($tokens[$k]->id !== T_THROW) {
                        continue;
                    }

                    $keeps  = false;
                    $opened = false;

                    for ($parens = 0, $a = $k; $a < $count; $a++) {
                        $opened  = $opened || $tokens[$a]->text === '(';
                        $parens += (int) ($tokens[$a]->text === '(') - (int) ($tokens[$a]->text === ')');
                        $keeps   = $keeps || self::handsOn($tokens, $a, $variable);

                        if ($parens === 0 && $opened) {
                            break;
                        }
                    }

                    if (!$keeps) {
                        $unwrapped[] = $class . ':' . $tokens[$k]->line . ' throws without ' . $variable;
                    }
                }
            }
        }

        sort($broad);
        sort($unwrapped);

        return ['broad' => $broad, 'unwrapped' => $unwrapped];
    }

    /**
     * One file's `use` block, as `short name => fully qualified`.
     *
     * Group and function imports are not used anywhere under `src/`, so this reads the plain form
     * and the aliased one and nothing else. A name this cannot find is resolved against the file's
     * own namespace by the caller, which is what PHP does.
     *
     * @param list<PhpToken> $tokens
     * @return array<string, string>
     */
    private static function imports(array $tokens): array
    {
        $imports = [];
        $count   = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_USE) {
                continue;
            }

            $name  = '';
            $alias = null;

            for ($j = $i + 1; $j < $count && $tokens[$j]->text !== ';'; $j++) {
                if ($tokens[$j]->text === '{' || $tokens[$j]->text === '(') {
                    // A trait's `use`, or a closure's — neither is an import.
                    continue 2;
                }

                if ($tokens[$j]->id === T_AS) {
                    $alias = '';
                    continue;
                }

                if ($tokens[$j]->id === T_STRING || $tokens[$j]->id === T_NAME_QUALIFIED) {
                    if ($alias === null) {
                        $name .= $tokens[$j]->text;
                    } else {
                        $alias = $tokens[$j]->text;
                    }
                } elseif ($tokens[$j]->text === '\\' && $alias === null) {
                    $name .= '\\';
                }
            }

            if ($name !== '') {
                // strrpos() answers false for an unqualified import — `use Closure;` — and (int)
                // false is 0, which would key it under its own name minus the first letter.
                $separator = strrpos($name, '\\');
                $short     = $separator === false ? $name : substr($name, $separator + 1);

                $imports[$alias ?? $short] = $name;
            }
        }

        return $imports;
    }

    /**
     * One file's `throw new X` sites, as `[short name, line]` pairs.
     *
     * A rethrow (`throw $caught;`) is not one: it names no class, and what it is rethrowing was
     * already counted where it was made.
     *
     * @param list<PhpToken> $tokens
     * @return list<array{string, int}>
     */
    private static function thrown(array $tokens): array
    {
        $throws = [];
        $count  = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_THROW) {
                continue;
            }

            for ($j = $i + 1; $j < $count && $tokens[$j]->is(T_WHITESPACE); $j++);

            if (($tokens[$j] ?? null)?->id !== T_NEW) {
                continue;
            }

            for ($k = $j + 1; $k < $count && $tokens[$k]->is(T_WHITESPACE); $k++);

            $name = $tokens[$k] ?? null;

            if ($name?->id === T_STRING || $name?->id === T_NAME_QUALIFIED || $name?->id === T_NAME_FULLY_QUALIFIED) {
                $throws[] = [$name->text, $name->line];
            }
        }

        return $throws;
    }

    /**
     * True if $type is, or contains, `array`.
     *
     * A union counts: `array|false` is still an array on the branch that matters, and
     * {@link \Phpanta\Support\Route::matches()} is exactly that shape.
     *
     * @param ?ReflectionType $type
     * @return bool
     */
    private static function namesAnArray(?ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName() === 'array';
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (self::namesAnArray($member)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * One file's closures that name an array or carry a `#[BareArray]`, keyed `Class::method()_n`:
     * the nth such closure written in that method, in source order, so adding a closure that names
     * no array renumbers nothing. Valued `[bare, reason]` — bare if its return, or a parameter that
     * is not variadic, names `array`; the reason its excuse gives, `''` for one that gives none, or
     * null for no excuse.
     *
     * Read from the tokens, because reflection reaches a closure only once it exists, and nothing
     * here runs one.
     *
     * @param string                  $path
     * @param ReflectionClass<object> $reflection The class the file declares.
     * @return array<string, array{bool, ?string}>
     */
    private static function closures(string $path, ReflectionClass $reflection): array
    {
        $methods = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->getFileName() === $path) {
                $methods[$method->getName()] = [$method->getStartLine(), $method->getEndLine()];
            }
        }

        $tokens  = PhpToken::tokenize(file_get_contents($path));
        $counted = [];
        $found   = [];

        foreach ($tokens as $i => $token) {
            if (!$token->is([T_FN, T_FUNCTION])) {
                continue;
            }

            $open = self::meaningful($tokens, $i + 1);

            if ($tokens[$open]->text === '&') {
                $open = self::meaningful($tokens, $open + 1);
            }

            // A function with a name is a method, and reflection reads those.
            if ($tokens[$open]->text !== '(') {
                continue;
            }

            [$bare, $close] = self::arrayInParameters($tokens, $open);
            $bare           = self::arrayInReturn($tokens, $close) || $bare;
            $reason         = self::excuseBefore($tokens, $i);

            if (!$bare && $reason === null) {
                continue;
            }

            $where = '<no method>';

            foreach ($methods as $name => [$from, $to]) {
                if ($token->line >= $from && $token->line <= $to) {
                    $where = $name . '()';
                    break;
                }
            }

            $counted[$where] = ($counted[$where] ?? 0) + 1;
            $found[$reflection->getName() . '::' . $where . '_' . $counted[$where]] = [$bare, $reason];
        }

        return $found;
    }

    /**
     * Whether a parameter in the list opening at $open names `array` in its type — a variadic
     * excepted — and the index of the `)` that closes the list.
     *
     * @param list<PhpToken> $tokens
     * @param int            $open
     * @return array{bool, int}
     */
    private static function arrayInParameters(array $tokens, int $open): array
    {
        $bare     = false;
        $typed    = false;
        $variadic = false;
        $inType   = true;
        $depth    = 0;

        for ($i = $open; isset($tokens[$i]); $i++) {
            $text = $tokens[$i]->text;

            if ($text === '(') {
                $depth++;
                continue;
            }

            if ($text === ')' && --$depth === 0) {
                return [$bare || ($typed && !$variadic), $i];
            }

            if ($depth !== 1) {
                continue;
            }

            if ($text === ',') {
                $bare     = $bare || ($typed && !$variadic);
                $typed    = false;
                $variadic = false;
                $inType   = true;
            } elseif ($tokens[$i]->is(T_ELLIPSIS)) {
                $variadic = true;
            } elseif ($tokens[$i]->is(T_VARIABLE)) {
                $inType = false;
            } elseif ($inType && $tokens[$i]->is(T_ARRAY)) {
                $typed = true;
            }
        }

        return [$bare, $i];
    }

    /**
     * Whether the closure whose parameter list closes at $close declares a return type naming
     * `array` — past its `use (…)`, which comes between the two.
     *
     * @param list<PhpToken> $tokens
     * @param int            $close
     * @return bool
     */
    private static function arrayInReturn(array $tokens, int $close): bool
    {
        $i = self::meaningful($tokens, $close + 1);

        if ($tokens[$i]->is(T_USE)) {
            for ($depth = 0; isset($tokens[$i]); $i++) {
                $depth += (int) ($tokens[$i]->text === '(');

                if ($tokens[$i]->text === ')' && --$depth === 0) {
                    break;
                }
            }

            $i = self::meaningful($tokens, $i + 1);
        }

        if ($tokens[$i]->text !== ':') {
            return false;
        }

        for ($i++; isset($tokens[$i]) && $tokens[$i]->text !== '{' && !$tokens[$i]->is(T_DOUBLE_ARROW); $i++) {
            if ($tokens[$i]->is(T_ARRAY)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The reason a `#[BareArray]` written just before the closure at $at gives — `''` where it gives
     * none — or null where none is written there.
     *
     * @param list<PhpToken> $tokens
     * @param int            $at
     * @return string|null
     */
    private static function excuseBefore(array $tokens, int $at): ?string
    {
        while (true) {
            $at--;

            while ($at >= 0 && $tokens[$at]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STATIC])) {
                $at--;
            }

            if ($at < 0 || $tokens[$at]->text !== ']') {
                return null;
            }

            // Back to the `#[` that opens this group of attributes.
            $end = $at;

            for ($depth = 0; $at >= 0; $at--) {
                $depth += (int) ($tokens[$at]->text === ']')
                    - (int) ($tokens[$at]->text === '[' || $tokens[$at]->is(T_ATTRIBUTE));

                if ($depth === 0) {
                    break;
                }
            }

            $excuse = false;
            $reason = '';

            for ($k = $at; $k <= $end; $k++) {
                if ($tokens[$k]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    $excuse = $excuse || str_ends_with($tokens[$k]->text, 'BareArray');
                } elseif ($tokens[$k]->is(T_CONSTANT_ENCAPSED_STRING)) {
                    $reason .= stripcslashes(substr($tokens[$k]->text, 1, -1));
                }
            }

            if ($excuse) {
                return $reason;
            }
        }
    }

    /**
     * The index of the first token at or after $i that is not whitespace or a comment.
     *
     * @param list<PhpToken> $tokens
     * @param int            $i
     * @return int
     */
    private static function meaningful(array $tokens, int $i): int
    {
        while (isset($tokens[$i]) && $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            $i++;
        }

        return $i;
    }

    /**
     * Whether the token at $at is $variable itself, handed on — not a member read off it, such as
     * its message, which keeps the words and drops the cause.
     *
     * @param list<PhpToken> $tokens
     * @param int            $at
     * @param string         $variable
     * @return bool
     */
    private static function handsOn(array $tokens, int $at, string $variable): bool
    {
        return $tokens[$at]->is(T_VARIABLE)
            && $tokens[$at]->text === $variable
            && !in_array(($tokens[self::meaningful($tokens, $at + 1)] ?? null)?->text, ['->', '?->', '[', '::'], true);
    }

    /**
     * Every `array` in a declared type under `src/`, judged against its `#[BareArray]`.
     *
     * `excused` is keyed `Class::member()` and valued by the reason; `unexcused` and `stale` are
     * lists of names, which is what an assertion against `[]` prints usefully.
     *
     * @return array{excused: array<string, string>, unexcused: list<string>, stale: list<string>}
     */
    private static function bareArrays(): array
    {
        $excused   = [];
        $unexcused = [];
        $stale     = [];

        foreach (SourceTree::framework()->classes() as $path => $class) {
            $reflection = new ReflectionClass($class);
            $declared   = [];

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || !$method->isUserDefined()) {
                    continue;
                }

                $bare = self::namesAnArray($method->getReturnType());

                foreach ($method->getParameters() as $parameter) {
                    // A variadic is PHP's own check and needs no excuse; see the rule this test
                    // states about what a class takes versus what it stores.
                    if (!$parameter->isVariadic() && self::namesAnArray($parameter->getType())) {
                        $bare = true;
                    }
                }

                $declared[$class . '::' . $method->getName() . '()'] = [$bare, $method];
            }

            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                // An enum's $name and $value are the engine's, not the framework's.
                if ($reflection->isEnum() && in_array($property->getName(), ['name', 'value'], true)) {
                    continue;
                }

                $declared[$class . '::$' . $property->getName()] = [
                    self::namesAnArray($property->getType()),
                    $property,
                ];
            }

            // A typed constant is a declaration like any other; an enum's cases carry no type.
            foreach ($reflection->getReflectionConstants() as $constant) {
                if ($constant->getDeclaringClass()->getName() === $class) {
                    $declared[$class . '::' . $constant->getName()] = [
                        self::namesAnArray($constant->getType()),
                        $constant,
                    ];
                }
            }

            foreach ($declared as $name => [$bare, $member]) {
                $attributes = $member->getAttributes(BareArray::class);

                if ($bare && $attributes === []) {
                    $unexcused[] = $name;
                } elseif ($bare) {
                    $excused[$name] = $attributes[0]->newInstance()->reason;
                } elseif ($attributes !== []) {
                    $stale[] = $name;
                }
            }

            foreach (self::closures($path, $reflection) as $name => [$bare, $reason]) {
                if ($bare && ($reason ?? '') === '') {
                    $unexcused[] = $name;
                } elseif ($bare) {
                    $excused[$name] = $reason;
                } elseif ($reason !== null) {
                    $stale[] = $name;
                }
            }
        }

        ksort($excused);
        sort($unexcused);
        sort($stale);

        return ['excused' => $excused, 'unexcused' => $unexcused, 'stale' => $stale];
    }

    /**
     * Every call to a {@link self::COLLECTION_MEMBERS} function under `src/`, judged against its
     * `#[BareCall]`.
     *
     * Keyed `Class::method function`, which is the granularity the attribute has — reflection for
     * where each method starts and ends, the tokenizer for where the call is, and the two met in
     * the middle. A call inside a closure lands on the method the closure is written in, which is
     * the answer that lets an excuse be attached to something.
     *
     * @return array{excused: array<string, string>, unexcused: list<string>, stale: list<string>}
     */
    private static function bareCalls(): array
    {
        $excused   = [];
        $unexcused = [];
        $stale     = [];

        foreach (SourceTree::framework()->classes() as $path => $class) {
            if (in_array(basename($path), self::COLLECTION_FILES, true)) {
                continue;
            }

            $methods  = [];
            $declared = [];

            foreach (new ReflectionClass($class)->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || !$method->isUserDefined()) {
                    continue;
                }

                $methods[$method->getName()] = [$method->getStartLine(), $method->getEndLine()];

                foreach ($method->getAttributes(BareCall::class) as $attribute) {
                    $instance = $attribute->newInstance();
                    $declared[$method->getName() . ' ' . $instance->function] = $instance->reason;
                }
            }

            $called = [];

            foreach (self::arrayCalls($path) as [$function, $line]) {
                $where = '<no method>';

                foreach ($methods as $name => [$from, $to]) {
                    if ($line >= $from && $line <= $to) {
                        $where = $name;
                        break;
                    }
                }

                $called[$where . ' ' . $function] = $function;
            }

            foreach ($called as $call => $function) {
                if (isset($declared[$call])) {
                    $excused[$class . '::' . $call] = $declared[$call];
                    continue;
                }

                $unexcused[] = $class . '::' . $call . ' — ' . self::COLLECTION_MEMBERS[$function] . ' does this';
            }

            foreach (array_keys($declared) as $call) {
                if (!isset($called[$call])) {
                    $stale[] = $class . '::' . $call;
                }
            }
        }

        ksort($excused);
        sort($unexcused);
        sort($stale);

        return ['excused' => $excused, 'unexcused' => $unexcused, 'stale' => $stale];
    }

    /**
     * One file's calls to a {@link self::COLLECTION_MEMBERS} function, as `[name, line]` pairs.
     *
     * A name is only a call when a `(` follows it and neither `->` nor `::` nor `function` comes
     * first — so a method that happened to be called `map` and the declaration of one are both left
     * alone. Whitespace is stepped over on each side, since `array_map (` is the same call.
     *
     * @param string $path
     * @return list<array{string, int}>
     */
    private static function arrayCalls(string $path): array
    {
        $tokens = PhpToken::tokenize(file_get_contents($path));
        $count  = count($tokens);
        $calls  = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // `\array_map(` is the same call, written from inside a namespace.
            $name = ltrim($token->text, '\\');

            if (!$token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || !isset(self::COLLECTION_MEMBERS[$name])) {
                continue;
            }

            $before = ($tokens[$i - 1] ?? null)?->is(T_WHITESPACE) === true
                ? $tokens[$i - 2] ?? null
                : $tokens[$i - 1] ?? null;

            if (in_array($before?->text, ['->', '?->', '::', 'function'], true)) {
                continue;
            }

            $after = ($tokens[$i + 1] ?? null)?->is(T_WHITESPACE) === true
                ? $tokens[$i + 2] ?? null
                : $tokens[$i + 1] ?? null;

            if ($after?->text === '(') {
                $calls[] = [$name, $token->line];
            }
        }

        return $calls;
    }

    /**
     * Every literal under `src/` that a vocabulary already spells, judged against its
     * `#[BareString]`.
     *
     * Two passes, because the second clause is a question about the whole tree: the first collects
     * what every file writes, the second asks of each literal whether an enum the file names can
     * spell it, or whether another class writes it too.
     *
     * @return array{excused: array<string, string>, unexcused: list<string>, stale: list<string>}
     */
    private static function bareStrings(): array
    {
        $written = [];
        $named   = [];
        $shorts  = [];

        foreach (SourceTree::framework()->classes() as $path => $class) {
            if (enum_exists($class)) {
                $shorts[substr($class, strrpos($class, '\\') + 1)] = $class;
                continue;
            }

            [$written[$class], $named[$class]] = self::read($path);
        }

        $writers = [];

        foreach ($written as $class => $literals) {
            foreach ($literals as $literal) {
                $writers[$literal][$class] = true;
            }
        }

        $excused   = [];
        $unexcused = [];
        $stale     = [];

        foreach ($written as $class => $literals) {
            $vocabulary = [];

            foreach ($named[$class] as $mentioned => $_) {
                $enum = $shorts[$mentioned] ?? null;

                if ($enum === null || !new ReflectionEnum($enum)->isBacked()) {
                    continue;
                }

                foreach ($enum::cases() as $case) {
                    if (is_string($case->value)) {
                        $vocabulary[$case->value] = $enum . '::' . $case->name;
                    }
                }
            }

            $bare = [];

            foreach (array_unique($literals) as $literal) {
                if (isset($vocabulary[$literal])) {
                    $bare[$literal] = $vocabulary[$literal] . ' spells it';
                } elseif (count($writers[$literal]) > 1) {
                    $bare[$literal] = 'also written in ' . implode(
                        ', ',
                        array_diff(array_keys($writers[$literal]), [$class]),
                    );
                }
            }

            $declared = [];

            foreach (new ReflectionClass($class)->getAttributes(BareString::class) as $attribute) {
                $instance = $attribute->newInstance();
                $declared[$instance->literal] = $instance->reason;
            }

            foreach ($bare as $literal => $why) {
                if (isset($declared[$literal])) {
                    $excused[$class . ' ' . $literal] = $declared[$literal];
                } else {
                    $unexcused[] = $class . ' ' . var_export($literal, true) . ' — ' . $why;
                }
            }

            foreach (array_keys($declared) as $literal) {
                if (!isset($bare[$literal])) {
                    $stale[] = $class . ' ' . var_export($literal, true);
                }
            }
        }

        ksort($excused);
        sort($unexcused);
        sort($stale);

        return ['excused' => $excused, 'unexcused' => $unexcused, 'stale' => $stale];
    }

    /**
     * One file's word-shaped literals and the bare names it mentions.
     *
     * Literals inside an attribute's arguments are skipped: a reason is prose about the code, the
     * way a docblock is. The depth count opens on `#[` and closes on the matching `]`, which is the
     * only bracket an attribute's arguments can nest.
     *
     * The names are every bare `T_STRING` in the file, which is how a vocabulary is found to be "in
     * reach": if a file writes `HttpMethod` anywhere at all, it could have written
     * `HttpMethod::Get`. Reading the `use` block instead would have been narrower and would miss a
     * grouped or aliased import; this misses nothing and costs a few coincidences, which is the
     * right way round for a rule whose exceptions are read one at a time.
     *
     * @param string $path
     * @return array{list<string>, array<string, true>}
     */
    private static function read(string $path): array
    {
        $literals = [];
        $names    = [];
        $depth    = 0;

        foreach (PhpToken::tokenize(file_get_contents($path)) as $token) {
            if ($token->id === T_ATTRIBUTE) {
                $depth++;
                continue;
            }

            if ($depth > 0) {
                $depth += (int) ($token->text === '[') - (int) ($token->text === ']');
                continue;
            }

            if ($token->id === T_STRING) {
                $names[$token->text] = true;
                continue;
            }

            if ($token->id !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = self::decode($token->text);

            // No letter and no digit is punctuation — structure rather than a name. `'/'`, `', '`
            // and `"\n"` are the whole of what this drops, and naming any of them would read worse.
            if (preg_match('/[A-Za-z0-9]/', $literal) === 1) {
                $literals[] = $literal;
            }
        }

        return [$literals, $names];
    }

    /**
     * A `T_CONSTANT_ENCAPSED_STRING`'s text, as the value it stands for.
     *
     * Compared as values rather than as source, so `'x'` and `"x"` are one literal and `"\n"` is
     * one character rather than two. A double-quoted token that interpolates is not this token
     * type at all, so `stripcslashes()` has nothing to get wrong.
     *
     * @param string $text
     * @return string
     */
    private static function decode(string $text): string
    {
        $body = substr($text, 1, -1);

        return $text[0] === "'"
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
            : stripcslashes($body);
    }
}
