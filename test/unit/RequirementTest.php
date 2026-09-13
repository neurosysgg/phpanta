<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use ArrayObject;
use Closure;
use Phpanta\Exception\RequirementException;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Health\Area;
use Phpanta\Model\Health\ByteFloor;
use Phpanta\Model\Health\ExtensionRequirement;
use Phpanta\Model\Health\Finding;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthResult;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Health\Level;
use Phpanta\Model\Health\Outcome;
use Phpanta\Model\Health\Requirement;
use Phpanta\Model\Health\SecondsFloor;
use Phpanta\Model\Health\SettingRequirement;
use Phpanta\Model\Health\Toggle;
use Phpanta\Model\Health\Verdict;
use Phpanta\Model\Health\VersionRequirement;
use Phpanta\Service\ApiGate;
use Phpanta\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The requirement core: what a requirement is, what it comes to, and how a set of them reads.
 *
 * **Nothing here knows a site.** Every requirement below is either a built-in kind or a stub
 * declared in this file, and no test reads a site's app, its data files or the declared set — which
 * is the property that lets the core be lifted out whole, asserted from the outside. A site's own
 * declarations are its own suite's subject.
 *
 * The stub in {@link self::stub()} is also the smallest honest example of the extension point: a
 * requirement written by hand, which is what a user of the core does when no built-in kind fits.
 */
#[CoversClass(Verdict::class)]
#[CoversClass(Level::class)]
#[CoversClass(Area::class)]
#[CoversClass(Finding::class)]
#[CoversClass(Outcome::class)]
#[CoversClass(HealthResult::class)]
#[CoversClass(HealthFact::class)]
#[CoversClass(HealthSection::class)]
#[CoversClass(ByteFloor::class)]
#[CoversClass(SecondsFloor::class)]
#[CoversClass(Toggle::class)]
#[CoversClass(VersionRequirement::class)]
#[CoversClass(ExtensionRequirement::class)]
#[CoversClass(SettingRequirement::class)]
#[CoversClass(RequirementException::class)]
final class RequirementTest extends TestCase
{
    /** An extension name no build of PHP has, for the branches where one is not there. */
    private const string NO_SUCH_EXTENSION = 'no_such_extension';

    // ───────────────────────────── the verdict ─────────────────────────────

    /**
     * What a finding comes to at each level. The only place a warning is told from a failure.
     *
     * @param bool $met
     * @param Level $level
     * @param Verdict $expected
     * @return void
     */
    #[DataProvider('verdictProvider')]
    public function testAVerdictIsDerivedFromTheFindingAndTheLevel(bool $met, Level $level, Verdict $expected): void
    {
        self::assertSame($expected, Verdict::of($met, $level));
    }

    /**
     * @return iterable<string, array{bool, Level, Verdict}>
     */
    public static function verdictProvider(): iterable
    {
        yield 'met, required'   => [true, Level::Required, Verdict::Pass];
        yield 'met, optional'   => [true, Level::Optional, Verdict::Pass];
        yield 'unmet, required' => [false, Level::Required, Verdict::Fail];
        yield 'unmet, optional' => [false, Level::Optional, Verdict::Warn];
    }

    /**
     * A failure is the one label in capitals.
     *
     * @return void
     */
    public function testOnlyAFailureIsShouted(): void
    {
        self::assertSame('pass', Verdict::Pass->label());
        self::assertSame('warn', Verdict::Warn->label());
        self::assertSame('FAIL', Verdict::Fail->label());
    }

    // ───────────────────────────── constraints ─────────────────────────────

    /**
     * A size floor reads a value the way the engine does, and knows which number means no limit.
     *
     * **The unreadable row is the one this class exists for.** PHP reads `lots` as `0` "for
     * backwards compatibility", which for `post_max_size` is *unlimited* — so a floor that trusted
     * the engine's fallback would pass a php.ini typo as the most permissive setting there is.
     *
     * @param int $bytes
     * @param int|null $unlimitedAt
     * @param string $configured
     * @param bool $expected
     * @return void
     */
    #[DataProvider('byteFloorProvider')]
    public function testAByteFloor(int $bytes, ?int $unlimitedAt, string $configured, bool $expected): void
    {
        self::assertSame($expected, new ByteFloor($bytes, $unlimitedAt)->accepts($configured));
    }

    /**
     * @return iterable<string, array{int, int|null, string, bool}>
     */
    public static function byteFloorProvider(): iterable
    {
        yield 'exactly the floor'            => [ApiGate::MAX_BODY, 0, '8M', true];
        yield 'above it'                     => [ApiGate::MAX_BODY, 0, '128M', true];
        yield 'below it'                     => [ApiGate::MAX_BODY, 0, '4M', false];
        yield 'plain bytes'                  => [1024, null, '1024', true];
        yield '-1, declared unlimited'       => [ApiGate::MAX_BODY, -1, '-1', true];
        yield '-1, not declared'             => [ApiGate::MAX_BODY, null, '-1', false];
        yield '0, declared unlimited'        => [ApiGate::MAX_BODY, 0, '0', true];
        yield '0, where -1 is unlimited'     => [ApiGate::MAX_BODY, -1, '0', false];
        yield 'unreadable, 0 unlimited'      => [ApiGate::MAX_BODY, 0, 'lots', false];
    }

    /**
     * A size floor states itself in php.ini's shorthand where there is an exact one, and in bytes
     * where there is not.
     *
     * @param int $bytes
     * @param int|null $unlimitedAt
     * @param string $expected
     * @return void
     */
    #[DataProvider('byteDescriptionProvider')]
    public function testAByteFloorDescribesItself(int $bytes, ?int $unlimitedAt, string $expected): void
    {
        self::assertSame($expected, new ByteFloor($bytes, $unlimitedAt)->describe());
    }

    /**
     * @return iterable<string, array{int, int|null, string}>
     */
    public static function byteDescriptionProvider(): iterable
    {
        yield 'gigabytes'      => [1 << 30, null, 'at least 1G'];
        yield 'megabytes'      => [ApiGate::MAX_BODY, null, 'at least 8M'];
        yield 'kilobytes'      => [512 * 1024, null, 'at least 512K'];
        yield 'no shorthand'   => [1000, null, 'at least 1000'];
        yield 'nothing'        => [0, null, 'at least 0'];
        yield 'with no limit'  => [ApiGate::MAX_BODY, 0, 'at least 8M or 0 for no limit'];
    }

    /**
     * A duration floor, whose `'0'` is the trap: unlimited, below every floor, and falsy.
     *
     * @param int|null $unlimitedAt
     * @param string $configured
     * @param bool $expected
     * @return void
     */
    #[DataProvider('secondsFloorProvider')]
    public function testASecondsFloor(?int $unlimitedAt, string $configured, bool $expected): void
    {
        self::assertSame($expected, new SecondsFloor(30, $unlimitedAt)->accepts($configured));
    }

    /**
     * @return iterable<string, array{int|null, string, bool}>
     */
    public static function secondsFloorProvider(): iterable
    {
        yield '0, declared unlimited' => [0, '0', true];
        yield '0, not declared'       => [null, '0', false];
        yield 'exactly the floor'     => [0, '30', true];
        yield 'below it'              => [0, '29', false];
        yield 'not a number'          => [0, 'thirty', false];
        yield 'nothing at all'        => [0, '', false];
    }

    /**
     * @return void
     */
    public function testASecondsFloorDescribesItself(): void
    {
        self::assertSame('at least 30s', new SecondsFloor(30)->describe());
        self::assertSame('at least 30s or 0 for no limit', new SecondsFloor(30, 0)->describe());
    }

    /**
     * A switch reads like PHP reads a boolean directive — and `stderr` is neither on nor off.
     *
     * `display_errors = stderr` prints diagnostics somewhere other than the response, which is not
     * what a site means by off. Reading it as off would pass exactly the host this check is for.
     *
     * @param Toggle $toggle
     * @param string $configured
     * @param bool $expected
     * @return void
     */
    #[DataProvider('toggleProvider')]
    public function testASwitch(Toggle $toggle, string $configured, bool $expected): void
    {
        self::assertSame($expected, $toggle->accepts($configured));
    }

    /**
     * @return iterable<string, array{Toggle, string, bool}>
     */
    public static function toggleProvider(): iterable
    {
        yield 'on: 1'       => [Toggle::On, '1', true];
        yield 'on: On'      => [Toggle::On, 'On', true];
        yield 'on: yes'     => [Toggle::On, 'yes', true];
        yield 'on: 0'       => [Toggle::On, '0', false];
        yield 'on: empty'   => [Toggle::On, '', false];
        yield 'on: stderr'  => [Toggle::On, 'stderr', false];
        yield 'off: empty'  => [Toggle::Off, '', true];
        yield 'off: 0'      => [Toggle::Off, '0', true];
        yield 'off: Off'    => [Toggle::Off, 'Off', true];
        yield 'off: 1'      => [Toggle::Off, '1', false];
        yield 'off: stderr' => [Toggle::Off, 'stderr', false];
    }

    /**
     * @return void
     */
    public function testASwitchDescribesItselfByName(): void
    {
        self::assertSame('on', Toggle::On->describe());
        self::assertSame('off', Toggle::Off->describe());
    }

    // ───────────────────────────── declarations written wrong ─────────────────────────────

    /**
     * A declaration that cannot be checked is refused where it is written.
     *
     * The trailing newline is the `$`-against-`\z` trap: `$` matches before one, so a version
     * pattern ending in `$` would accept `"8.5\n"`.
     *
     * @param Closure(): object $declare
     * @return void
     */
    #[DataProvider('malformedProvider')]
    public function testAMalformedDeclarationIsRefused(Closure $declare): void
    {
        $this->expectException(RequirementException::class);

        $declare();
    }

    /**
     * @return iterable<string, array{Closure(): object}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'a negative size'          => [static fn(): object => new ByteFloor(-1)];
        yield 'a negative duration'      => [static fn(): object => new SecondsFloor(-1)];
        yield 'an unnamed extension'     => [static fn(): object => new ExtensionRequirement('')];
        yield 'an unnamed directive'     => [static fn(): object => new SettingRequirement('', Toggle::On)];
        yield 'not a version'            => [static fn(): object => new VersionRequirement('eight')];
        yield 'a version and a newline'  => [static fn(): object => new VersionRequirement("8.5\n")];
    }

    // ───────────────────────────── the built-in kinds ─────────────────────────────

    /**
     * The version floor, on the runtime running this test.
     *
     * @return void
     */
    public function testAVersionFloorComparesWithTheRunningEngine(): void
    {
        $met   = new VersionRequirement('8.5');
        $unmet = new VersionRequirement('99.0.0');

        self::assertEquals(new Finding(PHP_VERSION, true), $met->check());
        self::assertFalse($unmet->check()->met);
        self::assertSame('php', $met->name());
        self::assertSame(Area::Runtime, $met->area());
        self::assertSame(Level::Required, $met->level());
        self::assertSame('8.5 or later', $met->expected());
    }

    /**
     * Each of the four answers an extension can give, because which question failed is the whole
     * diagnostic: not there is a hosting ticket, registered-but-failing is a build of it missing
     * the part this installation uses.
     *
     * @param string $name
     * @param Closure(): bool|null $proof
     * @param string|null $found Null for the extension's own version.
     * @param bool $met
     * @return void
     */
    #[DataProvider('extensionProvider')]
    public function testAnExtensionSaysWhichQuestionFailed(
        string $name,
        ?Closure $proof,
        ?string $found,
        bool $met,
    ): void {
        self::assertEquals(
            new Finding($found ?? (string) phpversion($name), $met),
            new ExtensionRequirement($name, Level::Required, $proof)->check(),
        );
    }

    /**
     * @return iterable<string, array{string, Closure(): bool|null, string|null, bool}>
     */
    public static function extensionProvider(): iterable
    {
        yield 'registered'                   => ['Core', null, null, true];
        yield 'registered, and proven'       => ['Core', static fn(): bool => true, null, true];
        yield 'registered, proof fails'      => [
            'Core',
            static fn(): bool => false,
            'registered, but its proof fails',
            false,
        ];
        yield 'not registered, proof holds'  => [
            self::NO_SUCH_EXTENSION,
            static fn(): bool => true,
            'not registered, but its proof holds',
            true,
        ];
        yield 'not registered'               => [self::NO_SUCH_EXTENSION, null, 'not registered', false];
    }

    /**
     * The proof runs when the requirement is checked and not when it is declared — every request
     * that builds the set declares it, and only the one that asks should pay for asking.
     *
     * @return void
     */
    public function testAnExtensionsProofRunsOnlyWhenChecked(): void
    {
        /** @var ArrayObject<int, string> $calls */
        $calls       = new ArrayObject();
        $requirement = new ExtensionRequirement('Core', Level::Optional, static function () use ($calls): bool {
            $calls->append('proof');

            return true;
        });

        self::assertCount(0, $calls, 'declaring the requirement ran its proof');
        self::assertTrue($requirement->check()->met);
        self::assertCount(1, $calls);
        self::assertSame('working', $requirement->expected());
        self::assertSame('registered', new ExtensionRequirement('Core')->expected());
        self::assertSame(Area::Extensions, $requirement->area());
        self::assertSame(Level::Optional, $requirement->level());
    }

    /**
     * A directive the engine has never heard of fails, and says so.
     *
     * `ini_get()` answers `false` for it, which a cast would have made `''` — a misspelling
     * reported as a directive that is merely unset.
     *
     * @return void
     */
    public function testADirectiveTheEngineDoesNotKnowFailsLoudly(): void
    {
        $misspelled = new SettingRequirement('memory_limmit', new ByteFloor(0, -1));

        self::assertEquals(new Finding('no such directive', false), $misspelled->check());
    }

    /**
     * A directive the engine knows is read and held to its constraint.
     *
     * @return void
     */
    public function testADirectiveIsHeldToItsConstraint(): void
    {
        $floor       = new ByteFloor(0, -1);
        $requirement = new SettingRequirement('memory_limit', $floor, Level::Optional);

        self::assertEquals(new Finding((string) ini_get('memory_limit'), true), $requirement->check());
        self::assertSame('memory_limit', $requirement->name());
        self::assertSame(Area::Settings, $requirement->area());
        self::assertSame(Level::Optional, $requirement->level());
        self::assertSame($floor->describe(), $requirement->expected());
        self::assertSame($floor, $requirement->constraint);
    }

    // ───────────────────────────── one line ─────────────────────────────

    /**
     * An outcome's line: the verdict first, then what was found, then the floor — and an optional
     * requirement says so, which is what explains a `warn` where a reader expected a `FAIL`.
     *
     * @param Level $level
     * @param Finding $finding
     * @param string $value
     * @return void
     */
    #[DataProvider('outcomeProvider')]
    public function testAnOutcomeReadsVerdictFirst(Level $level, Finding $finding, string $value): void
    {
        $outcome = new Outcome(self::stub('name', Area::Runtime, $level, $finding->met), $finding);

        self::assertSame(str_pad('name', HealthFact::COLUMN) . ' ' . $value, $outcome->fact()->render());
    }

    /**
     * @return iterable<string, array{Level, Finding, string}>
     */
    public static function outcomeProvider(): iterable
    {
        yield 'a pass'                 => [Level::Required, new Finding('128M', true), 'pass  128M  (stated)'];
        yield 'a failure'              => [Level::Required, new Finding('4M', false), 'FAIL  4M  (stated)'];
        yield 'a warning, found blank' => [Level::Optional, new Finding('', false), 'warn  -  (stated, optional)'];
    }

    /**
     * A fact's value is rendered unless there is genuinely none.
     *
     * **`'0'` is the row that matters.** `max_execution_time` is `0` on a runtime with no limit —
     * a real answer — and a falsy test prints it as nothing.
     *
     * @param string $value
     * @param string $expected
     * @return void
     */
    #[DataProvider('factProvider')]
    public function testOnlyAnEmptyValueRendersAsNothing(string $value, string $expected): void
    {
        self::assertSame($expected, new HealthFact('name', $value)->render());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function factProvider(): iterable
    {
        yield 'a value'      => ['128M', 'name                 128M'];
        yield 'zero'         => ['0', 'name                 0'];
        yield 'the string 0' => ['0.0', 'name                 0.0'];
        yield 'off'          => ['', 'name                 -'];
    }

    /**
     * A name longer than the column pushes its own value across rather than being cut down.
     *
     * @return void
     */
    public function testALongNameOverrunsRatherThanTruncating(): void
    {
        self::assertSame(
            'a-name-much-longer-than-the-column over',
            new HealthFact('a-name-much-longer-than-the-column', 'over')->render(),
        );
    }

    /**
     * A section widens its column to its longest name, so three hundred directives do not each
     * overrun by a different amount.
     *
     * @return void
     */
    public function testASectionWidensToItsLongestName(): void
    {
        $long    = 'a-name-much-longer-than-the-column';
        $section = HealthSection::facts('caption', new Collection(HealthFact::class)->with(
            new HealthFact($long, 'over'),
            new HealthFact('short', 'back'),
        ));

        $column = strlen($long) + 1;

        self::assertSame(
            "caption\n  " . str_pad($long, $column) . " over\n  " . str_pad('short', $column) . ' back',
            $section->render(),
        );
    }

    /**
     * A section of plain lines is indented like a section of facts, and by the same class.
     *
     * @return void
     */
    public function testASectionOfLinesIsIndentedLikeOneOfFacts(): void
    {
        self::assertSame(
            "caption\n  first\n  second",
            HealthSection::lines('caption', 'first', 'second')->render(),
        );
    }

    /**
     * A document is its sections, a blank line apart, ending in the newline every body ends in.
     *
     * @return void
     */
    public function testADocumentJoinsItsSections(): void
    {
        self::assertSame(
            "a\n  x\n\nb\n  y\n",
            HealthSection::document(HealthSection::lines('a', 'x'), HealthSection::lines('b', 'y')),
        );
    }

    // ───────────────────────────── a set of them ─────────────────────────────

    /**
     * A required requirement unmet is a `503`; an optional one unmet is still a `200`.
     *
     * @param list<array{Level, bool}> $declared
     * @param HttpStatusCode $expected
     * @return void
     */
    #[DataProvider('statusProvider')]
    public function testTheStatusIsTheWorstVerdicts(array $declared, HttpStatusCode $expected): void
    {
        $requirements = new Collection(Requirement::class);

        foreach ($declared as $index => [$level, $met]) {
            $requirements = $requirements->with(self::stub('r' . $index, Area::Runtime, $level, $met));
        }

        self::assertSame($expected, HealthResult::of($requirements)->status());
    }

    /**
     * @return iterable<string, array{list<array{Level, bool}>, HttpStatusCode}>
     */
    public static function statusProvider(): iterable
    {
        yield 'nothing declared'       => [[], HttpStatusCode::Ok];
        yield 'everything met'         => [[[Level::Required, true], [Level::Optional, true]], HttpStatusCode::Ok];
        yield 'an optional one unmet'  => [[[Level::Required, true], [Level::Optional, false]], HttpStatusCode::Ok];
        yield 'a required one unmet'   => [
            [[Level::Required, false], [Level::Optional, true]],
            HttpStatusCode::ServiceUnavailable,
        ];
    }

    /**
     * Sections come in {@link Area}'s order whatever order they were declared in, an area with
     * nothing in it is left out, and the tally names every verdict even at zero.
     *
     * @return void
     */
    public function testTheReportIsSectionedByAreaAndTallied(): void
    {
        $report = HealthResult::of(new Collection(Requirement::class)->with(
            self::stub('late', Area::Deployment, Level::Required, false),
            self::stub('early', Area::Runtime, Level::Optional, false),
            self::stub('middle', Area::Settings, Level::Required, true),
        ))->render();

        self::assertMatchesRegularExpression(
            '/\Aruntime\n  early .*\n\nsettings\n  middle .*\n\ndeployment\n  late /',
            $report,
        );
        self::assertStringNotContainsString('extensions', $report);
        self::assertStringEndsWith("\n\n1 pass, 1 warn, 1 fail", $report);
    }

    /**
     * One area is only that area — in the lines and in the tally.
     *
     * @return void
     */
    public function testOneAreaIsOnlyThatArea(): void
    {
        $requirements = new Collection(Requirement::class)->with(
            self::stub('broken', Area::Deployment, Level::Required, false),
            self::stub('fine', Area::Settings, Level::Required, true),
        );

        $result = HealthResult::of($requirements, Area::Settings);

        self::assertSame(
            "settings\n  " . str_pad('fine', HealthFact::COLUMN) . " pass  yes  (stated)\n\n1 pass, 0 warn, 0 fail",
            $result->render(),
        );
        self::assertSame(HttpStatusCode::Ok, $result->status(), 'a failure in another area leaked into this one');
    }

    /**
     * Nothing declared is a tally of nothing, not an empty body.
     *
     * @return void
     */
    public function testNothingDeclaredIsATallyOfNothing(): void
    {
        self::assertSame('0 pass, 0 warn, 0 fail', HealthResult::of(new Collection(Requirement::class))->render());
    }

    /**
     * Every requirement is checked exactly once, however many times the result is read — so the
     * status and the body cannot disagree about a host that changed in between.
     *
     * @return void
     */
    public function testEachRequirementIsCheckedExactlyOnce(): void
    {
        /** @var ArrayObject<int, string> $calls */
        $calls  = new ArrayObject();
        $result = HealthResult::of(new Collection(Requirement::class)->with(
            self::stub('a', Area::Runtime, Level::Required, true, $calls),
            self::stub('b', Area::Settings, Level::Required, true, $calls),
        ));

        (void) $result->render();
        (void) $result->status();
        (void) $result->render();

        self::assertSame(['a', 'b'], $calls->getArrayCopy());
    }

    // ───────────────────────────── fixtures ─────────────────────────────

    /**
     * A requirement written by hand — the extension point, at its smallest.
     *
     * @param string $name
     * @param Area $area
     * @param Level $level
     * @param bool $met
     * @param ArrayObject<int, string>|null $calls Where each check is recorded, by name.
     * @return Requirement
     */
    private static function stub(
        string $name,
        Area $area,
        Level $level,
        bool $met,
        ?ArrayObject $calls = null,
    ): Requirement {
        return new readonly class ($name, $area, $level, $met, $calls) implements Requirement {
            /**
             * @param string $name
             * @param Area $area
             * @param Level $level
             * @param bool $met
             * @param ArrayObject<int, string>|null $calls
             */
            public function __construct(
                private string $name,
                private Area $area,
                private Level $level,
                private bool $met,
                private ?ArrayObject $calls,
            ) {}

            /**
             * @return string
             */
            public function name(): string
            {
                return $this->name;
            }

            /**
             * @return Area
             */
            public function area(): Area
            {
                return $this->area;
            }

            /**
             * @return Level
             */
            public function level(): Level
            {
                return $this->level;
            }

            /**
             * @return string
             */
            public function expected(): string
            {
                return 'stated';
            }

            /**
             * @return Finding
             */
            public function check(): Finding
            {
                $this->calls?->append($this->name);

                return new Finding($this->met ? 'yes' : 'no', $this->met);
            }
        };
    }
}
