<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\Support\Directory;
use Phpanta\Tool\Cli\Arity;
use Phpanta\Tool\Cli\Command;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Input;
use Phpanta\Tool\Cli\Option;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Cli\UsageException;
use Phpanta\Tool\Command\Export;
use Phpanta\Tool\Command\MergeCoverage;
use Phpanta\Tool\Command\MergeCoverageOption;
use Phpanta\Tool\Command\PushUpdate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The CLI layer under `tools/lib/Cli/`.
 *
 * Argument parsing is the part worth pinning, because its failure is silent: a flag the parser
 * does not recognise, dropped rather than refused, means a mistyped `--clover` reports success and
 * writes no report — and a word it does not recognise, taken as an operand nobody reads, meant
 * `push-update -n` was a real push. See docs/tooling.md.
 */
final class CliTest extends TestCase
{
    /**
     * A stub accepting both kinds of option, so one parser covers both shapes.
     *
     * Anonymous, for two reasons: the real commands are `final`, as they should be, and `phpcs`
     * holds this file to one named class.
     *
     * @param Arity|null $operands How many operands it takes; any number by default.
     * @return Command
     */
    private function command(?Arity $operands = null): Command
    {
        return new readonly class ($operands ?? Arity::atLeast(0)) implements Command {
            /**
             * @param Arity $arity
             */
            public function __construct(private Arity $arity) {}

            /**
             * @return string
             */
            public function name(): string
            {
                return 'stub';
            }

            /**
             * @return string
             */
            public function usage(): string
            {
                return '<operand> [--check] [--clover <file>]';
            }

            /**
             * @return string
             */
            public function description(): string
            {
                return 'A command that exists to be parsed for.';
            }

            /**
             * @return list<Option>
             */
            public function options(): array
            {
                return [...CliOptionFixture::cases(), ...MergeCoverageOption::cases()];
            }

            /**
             * @return Arity
             */
            public function operands(): Arity
            {
                return $this->arity;
            }

            /**
             * @param Input  $input
             * @param Output $output
             * @return ExitCode
             */
            public function run(Input $input, Output $output): ExitCode
            {
                return ExitCode::Success;
            }
        };
    }

    /**
     * @return void
     */
    public function testOperandsKeepTheirOrder(): void
    {
        $input = Input::parse(['first', 'second'], $this->command());

        $this->assertSame(2, $input->operandCount());
        $this->assertSame('first', $input->operand(0));
        $this->assertSame('second', $input->operand(1));
        $this->assertNull($input->operand(2));
    }

    /**
     * @return void
     */
    public function testABooleanFlagIsPresentOrAbsentAndCarriesNoValue(): void
    {
        $given = Input::parse(['x', '--check'], $this->command());

        $this->assertTrue($given->has(CliOptionFixture::Check));
        $this->assertNull($given->value(CliOptionFixture::Check));

        $this->assertFalse(Input::parse(['x'], $this->command())->has(CliOptionFixture::Check));
    }

    /**
     * `--check=no` reads as a check switched off and would be one switched on, so a flag that takes
     * no value refuses one rather than guessing.
     *
     * @return void
     */
    public function testABooleanFlagGivenAValueIsRefused(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("option '--check' takes no value");

        Input::parse(['x', '--check=no'], $this->command());
    }

    /**
     * Both spellings, because both get typed.
     *
     * @param list<string> $arguments
     * @return void
     */
    #[DataProvider('valueFlagProvider')]
    public function testAValueFlagIsReadEitherWayItIsWritten(array $arguments): void
    {
        $input = Input::parse($arguments, $this->command());

        $this->assertSame('build/clover.xml', $input->value(MergeCoverageOption::Clover));
        $this->assertTrue($input->has(MergeCoverageOption::Clover));
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function valueFlagProvider(): array
    {
        return [
            'separated by a space' => [['--clover', 'build/clover.xml']],
            'joined by an equals'  => [['--clover=build/clover.xml']],
        ];
    }

    /**
     * The case `getopt()` gets wrong, and the reason `merge-coverage.php` declined it: `getopt()`
     * stops at the first non-option argument, and `composer coverage` passes both paths first.
     *
     * @return void
     */
    public function testFlagsAreReadAfterOperandsToo(): void
    {
        $input = Input::parse(
            ['build/unit.cov', 'build/e2e', '--clover', 'c.xml', '--html', 'h'],
            $this->command(),
        );

        $this->assertSame('build/unit.cov', $input->operand(0));
        $this->assertSame('build/e2e', $input->operand(1));
        $this->assertSame(2, $input->operandCount());
        $this->assertSame('c.xml', $input->value(MergeCoverageOption::Clover));
        $this->assertSame('h', $input->value(MergeCoverageOption::Html));
    }

    /**
     * The whole point of `Command::options()`.
     *
     * @return void
     */
    public function testAnUndeclaredFlagIsRefusedRatherThanDropped(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("unknown option '--clovr'");

        Input::parse(['x', '--clovr', 'c.xml'], $this->command());
    }

    /**
     * A short option is refused, not taken as an operand — which is what made `push-update -n` a
     * real push.
     *
     * @return void
     */
    public function testAShortOptionIsRefused(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("unknown option '-n'");

        Input::parse(['-n'], $this->command());
    }

    /**
     * `--` ends the options, so what follows is an operand however it is spelled; a lone `-` is one
     * anyway.
     *
     * @return void
     */
    public function testADoubleDashEndsTheOptions(): void
    {
        $input = Input::parse(['-', '--', '-n', '--check'], $this->command());

        $this->assertSame(['-', '-n', '--check'], [$input->operand(0), $input->operand(1), $input->operand(2)]);
        $this->assertFalse($input->has(CliOptionFixture::Check));
    }

    /**
     * The whole point of `Command::operands()`, in both directions.
     *
     * @param Arity $arity
     * @param list<string> $arguments
     * @param string $expected
     * @return void
     */
    #[DataProvider('refusedOperandsProvider')]
    public function testOperandsTheCommandDoesNotTakeAreRefused(Arity $arity, array $arguments, string $expected): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage($expected);

        Input::parse($arguments, $this->command($arity));
    }

    /**
     * @return iterable<string, array{Arity, list<string>, string}>
     */
    public static function refusedOperandsProvider(): iterable
    {
        yield 'one where none is taken'   => [Arity::none(), ['dry-run'], 'takes no operands, and 1 was given'];
        yield 'none where one is needed'  => [Arity::exactly(1), [], 'takes exactly 1 operand, and 0 were given'];
        yield 'too few'                   => [Arity::atLeast(2), ['a'], 'takes at least 2 operands, and 1 was given'];
        yield 'too many'                  => [
            Arity::between(0, 1),
            ['a', 'b'],
            'takes 0 to 1 operands, and 2 were given',
        ];
    }

    /**
     * The motivating case, end to end: a word `push-update` does not take is a usage error before the
     * command so much as looks for a build — and so before anything is signed or sent.
     *
     * @param string $argument
     * @param string $expected
     * @return void
     */
    #[DataProvider('mistypedDryRunProvider')]
    public function testAMistypedDryRunIsAUsageErrorNotAPush(string $argument, string $expected): void
    {
        $error = fopen('php://memory', 'rw+');

        $code = Runner::execute(
            new PushUpdate(new Directory('/nonexistent'), 'https://example.test', '.config/example/update.key'),
            [$argument],
            new Output(fopen('php://memory', 'rw+'), $error),
        );

        rewind($error);

        $this->assertSame(ExitCode::Usage, $code);
        $this->assertStringContainsString($expected, (string) stream_get_contents($error));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mistypedDryRunProvider(): iterable
    {
        yield 'a short flag'     => ['-n', "unknown option '-n'"];
        yield 'a bare word'      => ['dry-run', 'takes no operands'];
        yield 'a value it takes no value for' => ['--dry-run=no', "option '--dry-run' takes no value"];
    }

    /**
     * @return void
     */
    public function testAValueFlagWithNoValueIsRefused(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("option '--clover' needs a value");

        Input::parse(['x', '--clover'], $this->command());
    }

    /**
     * The other spelling of the same mistake, which must get the same answer.
     *
     * `--clover=` storing an empty string would make `has()` say the flag was given and `value()`
     * hand the empty path on — `merge-coverage` would then die inside a report writer with a stack
     * trace rather than here with a sentence. A flag that takes a path either has one or does not.
     *
     * @return void
     */
    public function testAValueFlagWithAnEmptyValueIsRefusedTheSameWay(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("option '--clover' needs a value");

        Input::parse(['x', '--clover='], $this->command());
    }

    /**
     * A malformed command line is answered with the usage line and {@link ExitCode::Usage}, not with
     * whatever the command would have reported.
     *
     * @return void
     */
    public function testRunnerAnswersABadCommandLineWithItsUsage(): void
    {
        $error = fopen('php://memory', 'rw+');

        $code = Runner::execute(
            new MergeCoverage(PHPANTA_ROOT),
            ['unit.cov', 'e2e', '--nonsense'],
            new Output(fopen('php://memory', 'rw+'), $error),
        );

        rewind($error);
        $written = (string) stream_get_contents($error);

        $this->assertSame(ExitCode::Usage, $code);
        $this->assertStringContainsString("unknown option '--nonsense'", $written);
        $this->assertStringContainsString('usage: php tools/merge-coverage.php <unit.cov>... <e2e-dir>', $written);
    }

    /**
     * The two streams stay separate, which is what lets `> file` keep a command's product alone.
     *
     * @return void
     */
    public function testOutputKeepsTheTwoStreamsApart(): void
    {
        $out   = fopen('php://memory', 'rw+');
        $error = fopen('php://memory', 'rw+');

        $output = new Output($out, $error);
        $output->out('product');
        $output->error('report');

        rewind($out);
        rewind($error);

        $this->assertSame('product', stream_get_contents($out));
        $this->assertSame('report', stream_get_contents($error));
    }

    /**
     * A missing operand reads as a usage error to whoever typed it.
     *
     * @return void
     */
    public function testAMissingOperandIsAUsageError(): void
    {
        $error = fopen('php://memory', 'rw+');

        $code = Runner::execute(
            $this->command(Arity::exactly(1)),
            [],
            new Output(fopen('php://memory', 'rw+'), $error),
        );

        $this->assertSame(ExitCode::Usage, $code);
    }

    /**
     * @return void
     */
    public function testExitCodesAreTheOnesAShellExpects(): void
    {
        $this->assertSame(0, ExitCode::Success->value);
        $this->assertSame(1, ExitCode::Failure->value);
        $this->assertSame(2, ExitCode::Usage->value);
    }

    /**
     * **`merge-coverage` is the command a dropped flag is worst for.**
     *
     * Dropped rather than refused, a mistyped `--clover` reports success and writes no report — a
     * failure whose only symptom is a file that is not there, noticed whenever someone next goes
     * looking for it. `Command::options()` is what makes that a refusal, so this asserts
     * the declaration and the refusal together: a flag declared but not value-taking would parse,
     * and then `--clover` would swallow the path as an operand.
     *
     * @return void
     */
    public function testMergeCoverageDeclaresBothItsReportsAsFlagsThatTakeAPath(): void
    {
        foreach (MergeCoverageOption::cases() as $option) {
            $this->assertTrue($option->takesValue(), sprintf('--%s takes a path', $option->flag()));
        }

        $error = fopen('php://memory', 'rw+');

        $code = Runner::execute(
            new MergeCoverage(PHPANTA_ROOT),
            ['unit.cov', 'e2e', '--clovr', 'build/clover.xml'],
            new Output(fopen('php://memory', 'rw+'), $error),
        );

        rewind($error);

        $this->assertSame(ExitCode::Usage, $code);
        $this->assertStringContainsString("unknown option '--clovr'", (string) stream_get_contents($error));
    }

    /**
     * Both operands are required, and the report goes nowhere until they are there.
     *
     * The assertion worth having is the empty stdout: this command's product *is* stdout — a
     * coverage table — so a usage error that printed a header first would look like a report that
     * found nothing rather than a command that never ran.
     *
     * @return void
     */
    public function testMergeCoverageWithoutItsTwoInputsReportsUsageAndWritesNothing(): void
    {
        $out   = fopen('php://memory', 'rw+');
        $error = fopen('php://memory', 'rw+');

        $code = Runner::execute(new MergeCoverage(PHPANTA_ROOT), ['unit.cov'], new Output($out, $error));

        rewind($out);
        rewind($error);

        $this->assertSame(ExitCode::Usage, $code);
        $this->assertSame('', stream_get_contents($out));
        $this->assertStringContainsString(
            'usage: php tools/merge-coverage.php <unit.cov>... <e2e-dir>',
            (string) stream_get_contents($error),
        );
    }

    /**
     * Every command says the same things about itself, which is what `Runner` renders and `Input`
     * checks against. That each is run by a script of its name is a site's to check, since the
     * scripts that run the framework's commands are the site's.
     *
     * @param Command $command
     * @return void
     */
    #[DataProvider('commands')]
    public function testEveryCommandSaysWhatItIsAndHowItIsRun(Command $command): void
    {
        $this->assertNotSame('', $command->name());
        $this->assertNotSame('', $command->usage());
        $this->assertStringEndsWith('.', $command->description());
        $this->assertStringContainsString($command->usage(), Runner::usage($command));
        $this->assertInstanceOf(Arity::class, $command->operands());
    }

    /**
     * @return array<string, array{Command}>
     */
    public static function commands(): array
    {
        return [
            'merge-coverage' => [new MergeCoverage(PHPANTA_ROOT)],
            'push-update'    => [
                new PushUpdate(new Directory('/nonexistent'), 'https://example.test', '.config/example/update.key'),
            ],
            'export'         => [new Export(App::current())],
        ];
    }

    /**
     * The process's own two streams.
     *
     * A factory rather than a default argument because `STDOUT` and `STDERR` exist only under the
     * CLI SAPI, and a default is evaluated wherever the class is loaded — so this asserts the
     * constants are what it reached for, which is the whole content of the method.
     *
     * @return void
     */
    public function testStandardOutputIsTheProcessesOwnTwoStreams(): void
    {
        $output = Output::standard();

        $this->assertSame(STDOUT, new ReflectionProperty($output, 'out')->getValue($output));
        $this->assertSame(STDERR, new ReflectionProperty($output, 'error')->getValue($output));
    }
}
