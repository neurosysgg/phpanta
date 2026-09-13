<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Phpanta\Tool\Cli\Arity;
use Phpanta\Tool\Cli\Command;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Input;
use Phpanta\Tool\Cli\Output;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as Html;
use SebastianBergmann\CodeCoverage\Report\Text;
use SebastianBergmann\CodeCoverage\Report\Thresholds;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;
use Throwable;

/**
 * The MergeCoverage command. Merges the two suites' coverage into one report.
 *
 * PHPUnit measures the unit suite; the coverage prepend measures the dev server an end-to-end script
 * drives. They cover deliberately different things, so neither number alone says what a site's tests
 * actually reach. This unions them.
 *
 * Normally run through `composer coverage`, which produces both inputs first.
 */
final readonly class MergeCoverage implements Command
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $root The repository root, whose `src/` is the set being measured.
     */
    public function __construct(private string $root) {}

    /**
     * @return string
     */
    public function name(): string
    {
        return 'merge-coverage';
    }

    /**
     * @return string
     */
    public function usage(): string
    {
        return '<unit.cov>... <e2e-dir> [--clover <file>] [--html <dir>]';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return "Merge PHPUnit's coverage with the verify script's into one report.";
    }

    /**
     * @return list<MergeCoverageOption>
     */
    public function options(): array
    {
        return MergeCoverageOption::cases();
    }

    /**
     * At least one suite's coverage and the directory of the dev server's dumps.
     *
     * @return Arity
     */
    public function operands(): Arity
    {
        return Arity::atLeast(2);
    }

    /**
     * @param Input  $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        $count = $input->operandCount();

        // CodeCoverage insists on a driver even though nothing here collects: this command only
        // reads what the two suites already recorded. `composer coverage` sets the mode; a bare
        // run needs it too.
        try {
            $driver = new Selector()->forLineCoverage($filter = $this->filter());
        } catch (Throwable $exception) {
            $output->error(sprintf("%s: needs coverage mode: %s\n", $this->name(), $exception->getMessage()));

            return ExitCode::Failure;
        }

        $coverage = new CodeCoverage($driver, $filter);

        // Every operand but the last is a suite's coverage — the site's, and the framework's own —
        // and the last is the directory of the dev server's dumps.
        $suites = [];

        for ($i = 0; $i < $count - 1; $i++) {
            $suites[] = (string) $input->operand($i);
        }

        $dumps = (string) $input->operand($count - 1);

        // PHPUnit's halves, already processed: line hits attributed to the tests that produced them.
        // Serialization strips each suite's common prefix off every path, so put it back before the
        // suites are merged with one another and with the dev server's dumps, which carry absolute ones.
        $unitData = null;

        foreach ($suites as $suite) {
            $serialized = new Unserializer()->unserialize($suite);
            $data       = $serialized['codeCoverage'];

            foreach (array_keys($data->lineCoverage()) as $relative) {
                $data->renameFile($relative, $serialized['basePath'] . DIRECTORY_SEPARATOR . $relative);
            }

            if ($unitData === null) {
                $unitData = $data;
            } else {
                $unitData->merge($data);
            }
        }

        $coverage->setData($unitData);

        // The dev server's half, one dump per request it handled.
        $requests = glob($dumps . '/*.cov') ?: [];

        foreach ($requests as $dump) {
            /** @var array<string, array<int, int>> $raw */
            $raw = unserialize((string) file_get_contents($dump), ['allowed_classes' => false]);

            $coverage->append(RawCodeCoverageData::fromLineCoverage($raw), 'e2e:' . basename($dump, '.cov'));
        }

        $output->out(sprintf(
            "Merged %d suite(s) with %d request(s) from the verify script.\n",
            count($suites),
            count($requests),
        ));

        $report = $coverage->getReport();

        $output->out(new Text(Thresholds::default(), showUncoveredFiles: true)->process($report, true));

        if (($clover = $input->value(MergeCoverageOption::Clover)) !== null) {
            new Clover()->process($report, $clover);
        }

        if (($html = $input->value(MergeCoverageOption::Html)) !== null) {
            new Html()->process($report, $html);
        }

        return ExitCode::Success;
    }

    /**
     * The same set `phpunit.xml.dist`'s `<source>` names: every `.php` file under `src/`.
     *
     * `tools/` is deliberately not in it. The coverage figure is a claim about the shipped code, and
     * folding in code whose job is to shell out would either drop the number or invite contrived
     * tests to prop it up.
     *
     * @return Filter
     */
    private function filter(): Filter
    {
        $sources = [];
        // Both source trees: the site's and the framework's.
        foreach (['/src', '/phpanta/src'] as $tree) {
            $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root . $tree));

            foreach ($walk as $file) {
                if ($file->getExtension() === 'php') {
                    $sources[] = $file->getPathname();
                }
            }
        }

        $filter = new Filter();
        $filter->includeFiles($sources);

        return $filter;
    }
}
