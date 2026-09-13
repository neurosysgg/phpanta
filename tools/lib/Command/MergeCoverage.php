<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Phpanta\Tool\Cli\Command;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Input;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;
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
 * PHPUnit measures `test/unit/`; `tools/coverage-prepend.php` measures the dev server
 * `test/basic_test.sh` drives. They cover deliberately different things — see `docs/testing.md` —
 * so neither number alone says what the site's tests actually reach. This unions them.
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
        return '<unit.cov> <e2e-dir> [--clover <file>] [--html <dir>]';
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
     * @param Input  $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        $unit  = $input->operand(0);
        $dumps = $input->operand(1);

        if ($unit === null || $dumps === null) {
            $output->error(Runner::usage($this));

            return ExitCode::Usage;
        }

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

        // PHPUnit's half, already processed: line hits attributed to the tests that produced them.
        // Serialization strips the common prefix off every path, so put it back before merging in
        // the dev server's dumps, which carry absolute ones.
        $serialized = new Unserializer()->unserialize($unit);
        $unitData   = $serialized['codeCoverage'];

        foreach (array_keys($unitData->lineCoverage()) as $relative) {
            $unitData->renameFile($relative, $serialized['basePath'] . DIRECTORY_SEPARATOR . $relative);
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
            "Merged test/unit/ with %d request(s) from the verify script.\n",
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
     * `tools/` is deliberately not in it. The coverage figure is a claim about the shipped site, and
     * folding in code whose job is to shell out to `ffprobe` would either drop the number or invite
     * contrived tests to prop it up.
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
