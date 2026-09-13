<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Exception\ElementException;
use Phpanta\Exception\FormException;
use Phpanta\Exception\InputException;
use Phpanta\Exception\RouteException;
use Phpanta\Form\Email;
use Phpanta\Form\FieldEntry;
use Phpanta\Form\FieldId;
use Phpanta\Form\Form;
use Phpanta\Form\MaxLength;
use Phpanta\Form\OneOf;
use Phpanta\Form\Required;
use Phpanta\Form\Rule;
use Phpanta\Form\Submission;
use Phpanta\Form\WholeNumber;
use Phpanta\Http\CsrfField;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Input;
use Phpanta\Http\Request;
use Phpanta\Http\ServerVariable;
use Phpanta\Support\SearchableCollection;
use Phpanta\Test\TestRequest;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Language;
use Phpanta\Text\Verbatim;
use Phpanta\View\Html\Autocomplete;
use Phpanta\View\Html\ButtonType;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\FormMethod;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\InputType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A form: what a request sent, read into a submission and checked field by field, and the form
 * rendered back out of one — its token, its labels, its errors, and nothing a visitor typed that
 * could leave its attribute.
 */
#[CoversClass(Form::class)]
#[CoversClass(Submission::class)]
#[CoversClass(FieldEntry::class)]
#[CoversClass(FieldId::class)]
#[CoversClass(Required::class)]
#[CoversClass(MaxLength::class)]
#[CoversClass(Email::class)]
#[CoversClass(WholeNumber::class)]
#[CoversClass(OneOf::class)]
#[CoversClass(FormException::class)]
#[CoversClass(FrameworkText::class)]
#[CoversClass(Input::class)]
#[CoversClass(Request::class)]
#[CoversClass(Element::class)]
#[CoversClass(HtmlTag::class)]
#[CoversClass(HtmlAttribute::class)]
#[CoversClass(InputType::class)]
#[CoversClass(FormMethod::class)]
#[CoversClass(Autocomplete::class)]
#[CoversClass(ButtonType::class)]
final class FormTest extends TestCase
{
    /** A token as a session hands one out: what the form writes, whatever it is. */
    private const string TOKEN = 'a-token';

    /** Everything the fixture's form takes, sent the way a browser sends it, and all of it valid. */
    private const string VALID = 'name=Ada&email=ada%40example.org&password=hunter2&age=36&colour=blue&agree=on&ref=r1';

    private Form $form;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->form = new Form(FieldFixture::class, RoutePatternFixture::Form);
    }

    // ───────────────────────── reading ─────────────────────────

    /**
     * A first render has nothing in it and nothing wrong with it — and is not valid, because nothing
     * was sent to act on.
     *
     * @return void
     */
    public function testABlankIsEmptyWithNoErrorsAndIsNotValid(): void
    {
        $blank = $this->form->blank();

        foreach (FieldFixture::cases() as $field) {
            self::assertSame('', $blank->value($field), $field->name);
            self::assertNull($blank->error($field), $field->name);
        }

        self::assertFalse($blank->isValid());
    }

    /**
     * A form sent in full, and right, is valid and holds exactly what was sent — decoded.
     *
     * @return void
     */
    public function testASubmissionHoldsWhatWasSent(): void
    {
        $submission = $this->form->read(self::post(self::VALID));

        self::assertTrue($submission->isValid());
        self::assertSame('ada@example.org', $submission->value(FieldFixture::Email));
        self::assertSame('36', $submission->value(FieldFixture::Age));
        self::assertSame('on', $submission->value(FieldFixture::Agree));

        foreach (FieldFixture::cases() as $field) {
            self::assertNull($submission->error($field), $field->name);
        }
    }

    /**
     * A field not sent is empty, and its rules are asked of that — which is how an unticked
     * checkbox arrives, and how a required one is refused. The form token beside the fields, and
     * any name the form does not have, are not the form's to read.
     *
     * @return void
     */
    public function testAFieldNotSentIsEmptyAndItsRulesAreAskedOfThat(): void
    {
        $sent       = str_replace('&agree=on', '', self::VALID) . '&_csrf=x&extra=y';
        $submission = $this->form->read(self::post($sent));

        self::assertSame('', $submission->value(FieldFixture::Agree));
        self::assertSame(FrameworkText::FieldRequired, $submission->error(FieldFixture::Agree));
        self::assertFalse($submission->isValid());
    }

    /**
     * A POST that says nothing about its body sent no form: every field empty, every required one
     * refused — rather than an error of its own.
     *
     * @return void
     */
    public function testARequestWithNoFormHasEveryFieldEmpty(): void
    {
        $submission = $this->form->read(TestRequest::to(HttpMethod::Post, '/form')->request());

        self::assertSame(FrameworkText::FieldRequired, $submission->error(FieldFixture::Name));
        self::assertNull($submission->error(FieldFixture::Age));
        self::assertTrue($this->form->read(self::post(self::VALID))->isValid());
    }

    /**
     * Each field shows one thing to fix. Six spaces break both of the name's rules — nothing in it,
     * and longer than five — and the first rule declared is the one it shows.
     *
     * @return void
     */
    public function testTheFirstErrorPerFieldWins(): void
    {
        $submission = $this->form->read(self::post(str_replace('name=Ada', 'name=++++++', self::VALID)));

        self::assertSame(FrameworkText::FieldRequired, $submission->error(FieldFixture::Name));
        self::assertSame(
            'Please use at most 5 characters.',
            $this->form->read(self::post(str_replace('name=Ada', 'name=Adalbert', self::VALID)))
                ->error(FieldFixture::Name)
                ?->in(Language::English),
        );
    }

    /**
     * A body that cannot be read as a form is not a submission with errors; it is the
     * {@link InputException} the router answers with a 400, and the form lets it through.
     *
     * @param Request $request
     * @return void
     */
    #[DataProvider('unreadableProvider')]
    public function testAnUnreadableBodyIsAnInputException(Request $request): void
    {
        $this->expectException(InputException::class);

        (void) $this->form->read($request);
    }

    /**
     * @return iterable<string, array{Request}>
     */
    public static function unreadableProvider(): iterable
    {
        yield 'a body of another kind' => [
            TestRequest::to(HttpMethod::Post, '/form')
                ->withServer(ServerVariable::ContentType, 'multipart/form-data; boundary=x')
                ->withBody('--x--')
                ->request(),
        ];
        yield 'a field sent twice'     => [self::post(self::VALID . '&email=b%40example.org')];
        yield 'bytes that are not UTF-8' => [self::post('name=%FF')];
    }

    // ───────────────────────── the rules ─────────────────────────

    /**
     * Every rule's refusal and acceptance, in English — and an empty value passes every rule but
     * the one whose question it is.
     *
     * @param Rule        $rule
     * @param string      $value
     * @param string|null $refusal What it says, or null for nothing.
     * @return void
     */
    #[DataProvider('ruleProvider')]
    public function testEachRuleRefusesWhatItShouldAndOnlyThat(Rule $rule, string $value, ?string $refusal): void
    {
        self::assertSame($refusal, $rule->check($value)?->in(Language::English));
    }

    /**
     * @return iterable<string, array{Rule, string, string|null}>
     */
    public static function ruleProvider(): iterable
    {
        $required = 'Please fill this in.';
        $email    = 'Please enter an email address.';
        $number   = 'Please enter a whole number.';
        $choice   = 'Please choose one of the options.';

        yield 'required: empty'              => [new Required(), '', $required];
        yield 'required: only spaces'        => [new Required(), "  \t ", $required];
        yield 'required: a zero is something' => [new Required(), '0', null];
        yield 'required: filled'             => [new Required(), 'x', null];

        yield 'max length: at it'               => [new MaxLength(3), 'abc', null];
        yield 'max length: past it'             => [new MaxLength(3), 'abcd', 'Please use at most 3 characters.'];
        yield 'max length: characters, not bytes' => [new MaxLength(3), 'äöü', null];
        yield 'max length: one, in the singular' => [new MaxLength(1), 'ab', 'Please use at most 1 character.'];
        yield 'max length: empty'               => [new MaxLength(3), '', null];

        yield 'email: an address'           => [new Email(), 'ada@example.org', null];
        yield 'email: empty'                => [new Email(), '', null];
        yield 'email: no at sign'           => [new Email(), 'ada.example.org', $email];
        yield 'email: a trailing newline'   => [new Email(), "ada@example.org\n", $email];
        yield 'email: a domain with no dot' => [new Email(), 'ada@localhost', $email];
        yield 'email: a non-ASCII letter'   => [new Email(), 'müller@example.de', $email];

        yield 'whole number: zero'            => [new WholeNumber(), '0', null];
        yield 'whole number: negative'        => [new WholeNumber(), '-12', null];
        yield 'whole number: empty'           => [new WholeNumber(), '', null];
        yield 'whole number: a fraction'      => [new WholeNumber(), '1.5', $number];
        yield 'whole number: a leading zero'  => [new WholeNumber(), '007', $number];
        yield 'whole number: a plus'          => [new WholeNumber(), '+1', $number];
        yield 'whole number: an exponent'     => [new WholeNumber(), '1e3', $number];
        yield 'whole number: past an int'     => [new WholeNumber(), '9999999999999999999', $number];
        yield 'whole number: a trailing newline' => [new WholeNumber(), "1\n", $number];

        yield 'one of: a case'                 => [new OneOf(ChoiceFixture::class), 'red', null];
        yield 'one of: empty'                  => [new OneOf(ChoiceFixture::class), '', null];
        yield 'one of: a case, by its name'    => [new OneOf(ChoiceFixture::class), 'Red', $choice];
        yield 'one of: no case'                => [new OneOf(ChoiceFixture::class), 'green', $choice];
        yield 'one of: an int case, as text'   => [new OneOf(HttpStatusCode::class), '404', null];
        yield 'one of: no int case'            => [new OneOf(HttpStatusCode::class), '999', $choice];
    }

    /**
     * What a rule says is in the language it renders in, and every word a form says has its German.
     *
     * @return void
     */
    public function testEveryErrorIsWrittenInBothLanguages(): void
    {
        self::assertSame(
            'Bitte verwende höchstens 1.000 Zeichen.',
            new MaxLength(1000)->check(str_repeat('a', 1001))?->in(Language::German),
        );
        self::assertSame(
            'Please use at most 1,000 characters.',
            new MaxLength(1000)->check(str_repeat('a', 1001))?->in(Language::English),
        );
        self::assertSame('Bitte fülle das aus.', new Required()->check('')?->in(Language::German));

        foreach (
            [
                FrameworkText::FieldRequired,
                FrameworkText::FieldTooLong,
                FrameworkText::FieldNotEmail,
                FrameworkText::FieldNotWholeNumber,
                FrameworkText::FieldNotAChoice,
                FrameworkText::FieldChoose,
            ] as $case
        ) {
            self::assertTrue($case->translation()->has(Language::German), $case->name);
        }
    }

    /**
     * A rule declared with something it cannot check is refused where it is written.
     *
     * @return void
     */
    public function testAMaxLengthBelowOneIsRefused(): void
    {
        $this->expectException(FormException::class);

        (void) new MaxLength(0);
    }

    /**
     * @return void
     */
    public function testAChoiceOfSomethingThatIsNotABackedEnumIsRefused(): void
    {
        $this->expectException(FormException::class);

        (void) new OneOf(stdClass::class);
    }

    // ───────────────────────── declaring one ─────────────────────────

    /**
     * A form is an enum of fields; a catalog, which is an enum too, is not one.
     *
     * @return void
     */
    public function testAFormOfSomethingThatIsNotAFieldEnumIsRefused(): void
    {
        $this->expectException(FormException::class);

        (void) new Form(ChoiceFixture::class, RoutePatternFixture::Form);
    }

    /**
     * A field named `_csrf` would be sent twice, beside the form's own token, and every send would
     * be a 400 — so the form refuses to be declared with one.
     *
     * @return void
     */
    public function testAFieldNamedLikeTheTokenIsRefused(): void
    {
        $this->expectException(FormException::class);
        $this->expectExceptionMessage(CsrfField::Token->value);

        (void) new Form(OtherFieldFixture::class, RoutePatternFixture::Form);
    }

    /**
     * The address is a path filled in the way every link is, and refused the way every link is.
     *
     * @return void
     */
    public function testThePathItPostsToIsFilledInLikeAnyLink(): void
    {
        $form = new Form(FieldFixture::class, RoutePatternFixture::Item, 'first');

        self::assertStringStartsWith(
            '<form method="post" action="/items/first.json">',
            $form->render($form->blank(), self::TOKEN, new Verbatim('Send'))->render(0, Language::English),
        );

        $this->expectException(RouteException::class);

        (void) new Form(FieldFixture::class, RoutePatternFixture::Item);
    }

    /**
     * A submission answers only for its own form's fields, even one spelled the same.
     *
     * @return void
     */
    public function testASubmissionRefusesAnotherFormsField(): void
    {
        $this->expectException(FormException::class);

        (void) $this->form->blank()->value(OtherFieldFixture::Name);
    }

    /**
     * One built by hand without an entry for a field is refused on that field, rather than
     * answering for it with an empty string nobody sent.
     *
     * @return void
     */
    public function testASubmissionWithNoEntryForAFieldRefusesIt(): void
    {
        $submission = new Submission(FieldFixture::class, new SearchableCollection(FieldEntry::class), true);

        self::assertTrue($submission->isValid());

        $this->expectException(FormException::class);

        (void) $submission->error(FieldFixture::Name);
    }

    /**
     * Rendering one form out of another's submission is refused, not half-filled.
     *
     * @return void
     */
    public function testRenderingAnotherFormsSubmissionIsRefused(): void
    {
        $other = new Submission(OtherFieldFixture::class, new SearchableCollection(FieldEntry::class), false);

        $this->expectException(FormException::class);

        (void) $this->form->render($other, self::TOKEN, new Verbatim('Send'));
    }

    // ───────────────────────── rendering ─────────────────────────

    /**
     * The whole blank form, pinned: it posts to its address, carries the token first, labels every
     * control it can see, reads `required` and `maxlength` off the rules, lets a password manager
     * know which field is which, offers an empty first choice, puts a checkbox before its words,
     * gives a hidden field no label, and ends in a button that says it submits.
     *
     * @return void
     */
    public function testTheBlankFormRendersInFull(): void
    {
        self::assertSame(
            implode("\n", [
                '<form method="post" action="/form">',
                '  <input type="hidden" name="_csrf" value="a-token">',
                '  <div>',
                '    <label for="field-name">Your name</label>',
                '    <input type="text" id="field-name" name="name" required maxlength="5">',
                '  </div>',
                '  <div>',
                '    <label for="field-email">Email</label>',
                '    <input type="email" id="field-email" name="email" required autocomplete="email">',
                '  </div>',
                '  <div>',
                '    <label for="field-password">Password</label>',
                '    <input type="password" id="field-password" name="password" required maxlength="64"'
                . ' autocomplete="current-password">',
                '  </div>',
                '  <div>',
                '    <label for="field-age">Age</label>',
                '    <input type="number" id="field-age" name="age">',
                '  </div>',
                '  <div>',
                '    <label for="field-colour">Colour</label>',
                '    <select id="field-colour" name="colour">',
                '      <option value="">— choose —</option>',
                '      <option value="red">red</option>',
                '      <option value="blue">blue</option>',
                '    </select>',
                '  </div>',
                '  <div>',
                '    <input type="checkbox" id="field-agree" name="agree" required>',
                '    <label for="field-agree">I agree</label>',
                '  </div>',
                '  <div>',
                '    <label for="field-a%20note">Note</label>',
                '    <input type="text" id="field-a%20note" name="a note">',
                '  </div>',
                '  <input type="hidden" name="ref" value="">',
                '  <button type="submit">Send</button>',
                '</form>',
            ]),
            $this->render($this->form->blank(), Language::English),
        );
    }

    /**
     * The token's field is written by the form, under the name the guard reads — so a form that
     * renders is a form the guard accepts, and no page spells `_csrf` for itself.
     *
     * @return void
     */
    public function testTheFormWritesTheTokenUnderTheNameTheGuardReads(): void
    {
        self::assertStringContainsString(
            '<input type="hidden" name="' . CsrfField::Token->value . '" value="a-token">',
            $this->render($this->form->blank(), Language::English),
        );
    }

    /**
     * Every label names a control that exists, once — the one fact a label is for, and one that
     * fails in silence.
     *
     * @return void
     */
    public function testEveryLabelNamesExactlyOneControl(): void
    {
        $html = $this->render($this->form->read(self::post('')), Language::English);

        preg_match_all('/ for="([^"]+)"/', $html, $labels);
        preg_match_all('/ id="([^"]+)"/', $html, $ids);

        self::assertCount(7, $labels[1]);

        foreach ($labels[1] as $for) {
            self::assertSame(1, count(array_keys($ids[1], $for, true)), $for);
        }
    }

    /**
     * What a visitor typed comes back as text inside its attribute, whatever it holds.
     *
     * @return void
     */
    public function testAHostileValueComesBackInert(): void
    {
        $hostile = rawurlencode('"><script>alert(1)</script>');
        $html    = $this->render(
            $this->form->read(self::post(str_replace('name=Ada', 'name=' . $hostile, self::VALID))),
            Language::English,
        );

        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    /**
     * A password is never written back into the page, not even when the form comes back for a
     * mistake somewhere else.
     *
     * @return void
     */
    public function testAPasswordIsNeverRenderedBack(): void
    {
        $submission = $this->form->read(self::post(str_replace('email=ada%40example.org', 'email=nope', self::VALID)));

        self::assertFalse($submission->isValid());
        self::assertSame('hunter2', $submission->value(FieldFixture::Password));
        self::assertStringNotContainsString('hunter2', $this->render($submission, Language::English));
    }

    /**
     * Everything else a visitor sent comes back, so a mistake costs them one field, not the form.
     *
     * @return void
     */
    public function testEverythingElseSentIsRenderedBack(): void
    {
        $html = $this->render($this->form->read(self::post(self::VALID)), Language::English);

        self::assertStringContainsString('name="name" required maxlength="5" value="Ada">', $html);
        self::assertStringContainsString('name="email" required autocomplete="email" value="ada@example.org">', $html);
        self::assertStringContainsString('id="field-agree" name="agree" required checked>', $html);
        self::assertStringContainsString('<option value="blue" selected>blue</option>', $html);
        self::assertStringContainsString('<option value="red">red</option>', $html);
        self::assertStringContainsString('<input type="hidden" name="ref" value="r1">', $html);
    }

    /**
     * A field in error says so after its control, in the page's language, and the control names
     * it — so a screen reader reads the error with the field. One without says nothing.
     *
     * @return void
     */
    public function testAnErrorIsShownAndTheControlNamesIt(): void
    {
        $submission = $this->form->read(self::post(str_replace('email=ada%40example.org', 'email=nope', self::VALID)));
        $english    = $this->render($submission, Language::English);

        self::assertStringContainsString(
            '<input type="email" id="field-email" name="email" required autocomplete="email"'
            . ' aria-describedby="error-email" value="nope">'
            . "\n" . '    <p id="error-email">Please enter an email address.</p>',
            $english,
        );
        self::assertSame(1, substr_count($english, 'aria-describedby'));
        self::assertStringContainsString(
            '<p id="error-email">Bitte gib eine E-Mail-Adresse ein.</p>',
            $this->render($submission, Language::German),
        );
        self::assertStringNotContainsString(
            'aria-describedby',
            $this->render($this->form->blank(), Language::English),
        );
    }

    /**
     * An option shows its words in the page's language, and its value stays the key the rule reads.
     *
     * @return void
     */
    public function testAnOptionShowsItsWordsAndSendsItsKey(): void
    {
        $german = $this->render($this->form->read(self::post(self::VALID)), Language::German);

        self::assertStringContainsString('<option value="">— auswählen —</option>', $german);
        self::assertStringContainsString('<option value="red">rot</option>', $german);
        self::assertStringContainsString('<option value="blue" selected>blau</option>', $german);
    }

    /**
     * Where a form posts is an address, checked on the way out like any `href`.
     *
     * @return void
     */
    public function testTheActionIsCheckedLikeAnHref(): void
    {
        $this->expectException(ElementException::class);

        (void) new Element(HtmlTag::Form)->attr(HtmlAttribute::Action, 'javascript:alert(1)')->render();
    }

    /**
     * An input holds nothing, the way an `<img>` does.
     *
     * @return void
     */
    public function testAnInputIsVoid(): void
    {
        self::assertTrue(HtmlTag::Input->isVoid());
        self::assertFalse(HtmlTag::Select->isVoid());
    }

    /**
     * $submission's form, rendered in $language.
     *
     * @param Submission $submission
     * @param Language   $language
     * @return string
     */
    private function render(Submission $submission, Language $language): string
    {
        return $this->form->render($submission, self::TOKEN, new Verbatim('Send'))->render(0, $language);
    }

    /**
     * A POST to the form's address carrying $body as a url-encoded form.
     *
     * @param string $body
     * @return Request
     */
    private static function post(string $body): Request
    {
        return TestRequest::to(HttpMethod::Post, '/form')
            ->withServer(ServerVariable::ContentType, 'application/x-www-form-urlencoded')
            ->withBody($body)
            ->request();
    }
}
