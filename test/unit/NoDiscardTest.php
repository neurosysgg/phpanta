<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use NoDiscard;
use Phpanta\Test\SourceTree;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The results the framework may not drop on the floor, pinned in both directions.
 *
 * Everything immutable here builds by copying — `with()`, `allow()`, `attr()`, `containing()` —
 * and every one of those was named so that a discarded call would *read* as wrong: `$c->add(…)`
 * as a statement looks finished, `$c->with(…)` as a statement looks like somebody forgot the
 * left-hand side. A naming convention is a compiler's job done badly; PHP 8.5's `#[\NoDiscard]`
 * is the compiler doing it: a call whose result goes nowhere is an E_WARNING, and
 * `phpunit.xml.dist` has `failOnWarning`, so it is a failing test.
 *
 * The set is asserted rather than left to each class's own good judgement: an attribute nobody
 * remembered to add is a guarantee that silently is not there, and the page looks right either way.
 * Adding a copy-returning builder means adding it here too. A site's own builders are pinned by that
 * site's suite, which asks the same question of its own tree.
 *
 * `Auth::accepts()` and `Route::accepts()` are the members that are not builders, and they are the
 * ones where dropping the result is not merely useless but unsafe: each is a gate's entire decision.
 * `Auth::accepts()` is one of two ways to ask a credential — a `data/` file for the site and admin
 * gates, or a {@link \Phpanta\Support\PasswordHash} a site holds for a gate of its own.
 *
 * The gates wrapped around them are the same kind, one step on: `Auth::siteGate()`,
 * `Auth::adminGate()` and `Auth::challenge()` *return* the 401 rather than ending the request, so the
 * caller has to return it in turn, and a call whose result goes nowhere is the refusal thrown away
 * and the door left open. `Response::answer()` on every response and `App::handle()` are the builders
 * of that value, where a dropped one answered no one.
 *
 * `ApiGate::accepts()` is another of that kind and the strictest: it is the whole of the decision
 * that lets a request overwrite a site's source and its webroot. `ApiGate::spend()` beside it is
 * not a decision but a *record* and a lock — the lock it hands back is released the moment it is
 * dropped, and a dropped refusal is a write run that the gate refused. `FileLock::exclusive()` is the
 * same lock one level down. `UpdateApplier::apply()` and the ones on `UpdateReport` are the ordinary
 * kind: copy-returning builders and the rendered result, where a dropped call writes nothing into
 * the only account of the run that exists.
 *
 * The one on `Route` is the *method* gate rather than a credential gate. Each route answers for
 * itself, so a discarded `accepts()` is a POST reaching a controller that only reads — nothing
 * else stands in front of it. `Route::methods()` beside it carries no attribute: it hands back what
 * it was given, like {@link \Phpanta\Http\Request::path()}, and dropping it decides nothing.
 *
 * The deliberate discards are all in the tests — proving that a builder did not mutate what it was
 * called on, or that a bad argument threw — and each is spelled `(void)`, which says out loud what
 * the test is there to demonstrate.
 */
#[CoversNothing]
final class NoDiscardTest extends TestCase
{
    /**
     * Every method under `src/` whose result the caller must use.
     *
     * The copy-returning builders, the collections' query methods, and the gates' decisions.
     *
     * **The eleven query methods appear three times each**, and that is this test working rather than
     * failing. They are declared once in {@link \Phpanta\Support\TypedItems}; PHP flattens a
     * trait's members into each using class, so reflection reports them on `Collection` and
     * `SearchableCollection` as well as on the trait itself — which is exactly what
     * {@link self::noDiscardMethods()} prefers, since the direction this test can survive is counting
     * one twice rather than missing one entirely.
     *
     * Ten of the eleven copy nothing, unlike the builders around them, and are pinned for the other
     * half of the same reason: they are pure, so a result that goes nowhere is never anything but a
     * bug — and since the collections went lazy that includes the three materialising ones, where a
     * dropped `toValues()` is a chain that ran its callbacks for nothing at all.
     *
     * `settled()` is the eleventh and belongs to both halves: it copies like a builder *and* runs
     * whatever was pending. Dropping it is the one discard here that does work and then throws the
     * work away — which is exactly the mistake it was written to stop.
     *
     * @return void
     */
    public function testExactlyTheseResultsMayNotBeDiscarded(): void
    {
        self::assertSame(
            [
                'Phpanta\App::fault',
                'Phpanta\App::handle',
                'Phpanta\Controller\Layered::around',
                'Phpanta\Data\Database::first',
                'Phpanta\Data\Database::lastInsertId',
                'Phpanta\Data\Database::requirement',
                'Phpanta\Data\Database::select',
                'Phpanta\Data\Migrations::pending',
                'Phpanta\Data\Row::bool',
                'Phpanta\Data\Row::float',
                'Phpanta\Data\Row::int',
                'Phpanta\Data\Row::nullableInt',
                'Phpanta\Data\Row::nullableString',
                'Phpanta\Data\Row::string',
                'Phpanta\Form\Email::check',
                'Phpanta\Form\Form::blank',
                'Phpanta\Form\Form::read',
                'Phpanta\Form\Form::render',
                'Phpanta\Form\MaxBytes::check',
                'Phpanta\Form\MaxBytes::checkUpload',
                'Phpanta\Form\MaxLength::check',
                'Phpanta\Form\OneOf::cases',
                'Phpanta\Form\OneOf::check',
                'Phpanta\Form\Required::check',
                'Phpanta\Form\Submission::error',
                'Phpanta\Form\Submission::isValid',
                'Phpanta\Form\Submission::upload',
                'Phpanta\Form\Submission::value',
                'Phpanta\Form\Submission::withError',
                'Phpanta\Form\WholeNumber::check',
                'Phpanta\Http\Allow::with',
                'Phpanta\Http\Answer::header',
                'Phpanta\Http\Answer::withHeaders',
                'Phpanta\Http\Answer::withHeadersFirst',
                'Phpanta\Http\EmptyResponse::answer',
                'Phpanta\Http\FileResponse::answer',
                'Phpanta\Http\Input::choice',
                'Phpanta\Http\Input::flag',
                'Phpanta\Http\Input::has',
                'Phpanta\Http\Input::int',
                'Phpanta\Http\Input::text',
                'Phpanta\Http\JsonResponse::answer',
                'Phpanta\Http\PlainTextResponse::answer',
                'Phpanta\Http\RedirectResponse::answer',
                'Phpanta\Http\Security\ContentSecurityPolicy::allow',
                'Phpanta\Http\Session::attachTo',
                'Phpanta\Http\Session::endOn',
                'Phpanta\Http\Session::get',
                'Phpanta\Http\Session::messages',
                'Phpanta\Http\Session::token',
                'Phpanta\Http\Session::user',
                'Phpanta\Http\Session::with',
                'Phpanta\Http\Session::withMessage',
                'Phpanta\Http\Session::withToken',
                'Phpanta\Http\Session::withUser',
                'Phpanta\Http\Session::without',
                'Phpanta\Http\Session::withoutMessages',
                'Phpanta\Http\Session::withoutUser',
                'Phpanta\Http\Sitemap::answer',
                'Phpanta\Http\StreamResponse::answer',
                'Phpanta\Http\Upload::contents',
                'Phpanta\Http\Upload::keepAs',
                'Phpanta\Http\Upload::requirements',
                'Phpanta\Http\ViewResponse::answer',
                'Phpanta\Http\WithHeaders::answer',
                'Phpanta\Model\Health\HealthResult::render',
                'Phpanta\Model\Health\HealthResult::status',
                'Phpanta\Model\Update\PreviousRelease::completed',
                'Phpanta\Model\Update\ProbeReport::isClean',
                'Phpanta\Model\Update\ProbeReport::render',
                'Phpanta\Model\Update\UpdateReport::dryRun',
                'Phpanta\Model\Update\UpdateReport::failed',
                'Phpanta\Model\Update\UpdateReport::isComplete',
                'Phpanta\Model\Update\UpdateReport::kept',
                'Phpanta\Model\Update\UpdateReport::noted',
                'Phpanta\Model\Update\UpdateReport::removed',
                'Phpanta\Model\Update\UpdateReport::render',
                'Phpanta\Model\Update\UpdateReport::wrote',
                'Phpanta\Service\ApiGate::accepts',
                'Phpanta\Service\ApiGate::spend',
                'Phpanta\Service\Auth::accepts',
                'Phpanta\Service\Auth::adminGate',
                'Phpanta\Service\Auth::challenge',
                'Phpanta\Service\Auth::siteGate',
                'Phpanta\Service\FilesystemProbe::run',
                'Phpanta\Service\Login::attempt',
                'Phpanta\Service\ReleaseRecord::clear',
                'Phpanta\Service\ReleaseRecord::take',
                'Phpanta\Service\UpdateApplier::apply',
                'Phpanta\Service\UpdateApplier::rollback',
                'Phpanta\Support\Collection::first',
                'Phpanta\Support\Collection::isEmpty',
                'Phpanta\Support\Collection::join',
                'Phpanta\Support\Collection::last',
                'Phpanta\Support\Collection::map',
                'Phpanta\Support\Collection::settled',
                'Phpanta\Support\Collection::toArray',
                'Phpanta\Support\Collection::toKeys',
                'Phpanta\Support\Collection::toValues',
                'Phpanta\Support\Collection::unique',
                'Phpanta\Support\Collection::where',
                'Phpanta\Support\Collection::with',
                'Phpanta\Support\ErrorLog::faultLine',
                'Phpanta\Support\File::temporarySibling',
                'Phpanta\Support\FileLock::exclusive',
                'Phpanta\Support\FileLock::waitFor',
                'Phpanta\Support\Route::accepts',
                'Phpanta\Support\Route::through',
                'Phpanta\Support\RouteGroup::routes',
                'Phpanta\Support\SearchableCollection::find',
                'Phpanta\Support\SearchableCollection::first',
                'Phpanta\Support\SearchableCollection::isEmpty',
                'Phpanta\Support\SearchableCollection::join',
                'Phpanta\Support\SearchableCollection::last',
                'Phpanta\Support\SearchableCollection::map',
                'Phpanta\Support\SearchableCollection::settled',
                'Phpanta\Support\SearchableCollection::toArray',
                'Phpanta\Support\SearchableCollection::toKeys',
                'Phpanta\Support\SearchableCollection::toValues',
                'Phpanta\Support\SearchableCollection::unique',
                'Phpanta\Support\SearchableCollection::where',
                'Phpanta\Support\SearchableCollection::with',
                'Phpanta\Support\Throttle::attempt',
                'Phpanta\Support\Throttle::remaining',
                'Phpanta\Support\ThrottleVerdict::allowed',
                'Phpanta\Support\TypedItems::first',
                'Phpanta\Support\TypedItems::isEmpty',
                'Phpanta\Support\TypedItems::join',
                'Phpanta\Support\TypedItems::last',
                'Phpanta\Support\TypedItems::map',
                'Phpanta\Support\TypedItems::settled',
                'Phpanta\Support\TypedItems::toArray',
                'Phpanta\Support\TypedItems::toKeys',
                'Phpanta\Support\TypedItems::toValues',
                'Phpanta\Support\TypedItems::unique',
                'Phpanta\Support\TypedItems::where',
                'Phpanta\View\FaultPage::answer',
                'Phpanta\View\Html\Element::attr',
                'Phpanta\View\Html\Element::containing',
                'Phpanta\View\Html\Element::containingHtml',
                'Phpanta\View\Html\Vocabulary::withAttributes',
                'Phpanta\View\Html\Vocabulary::withTags',
            ],
            array_keys(self::noDiscardMethods()),
        );
    }

    /**
     * Every one carries its own sentence, because the default warning does not have one.
     *
     * A bare `#[\NoDiscard]` says the return value should be used, which the reader already
     * suspected. What is worth saying is *why the call did nothing* — that `with()` copies rather
     * than appends, that a dropped `allow()` never reaches the header — and that only fits in the
     * message. An empty attribute is the same shape of mistake as an unlabelled magic number.
     *
     * @return void
     */
    public function testEachOneSaysWhyTheResultMatters(): void
    {
        foreach (self::noDiscardMethods() as $method => $message) {
            self::assertNotSame('', $message, $method . ' carries a bare #[\NoDiscard]');
        }
    }

    /**
     * The `#[\NoDiscard]` methods under `src/`, keyed `Class::method`, valued by their message.
     *
     * Reflection rather than a source grep: what is being asserted here is which methods *carry* the
     * attribute, and the engine is the thing that knows.
     *
     * Traits are walked because they are where a `#[\NoDiscard]` could most easily hide: PHP
     * flattens a trait's methods into the using class, so `getDeclaringClass()` below names the
     * class and the trait's own file is never otherwise visited. A trait carrying one is counted
     * twice rather than not at all, which is the direction this test can survive.
     *
     * @return array<string, string>
     */
    private static function noDiscardMethods(): array
    {
        $found = [];

        foreach (SourceTree::framework()->classes() as $class) {
            foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                foreach ($method->getAttributes(NoDiscard::class) as $attribute) {
                    /** @var array{0?: string} $arguments */
                    $arguments = $attribute->getArguments();

                    $found[$class . '::' . $method->getName()] = $arguments[0] ?? '';
                }
            }
        }

        ksort($found);

        return $found;
    }
}
