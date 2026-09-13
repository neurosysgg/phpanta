<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Exception\UpdateException;
use Phpanta\Http\Answer;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\TextBody;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Update\ApplyManifest;
use Phpanta\Model\Update\Deployment;
use Phpanta\Model\Update\ProbeReport;
use Phpanta\Service\Api\UpdateProbe;
use Phpanta\Service\FilesystemProbe;
use Phpanta\Support\Directory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `update v1 probe`: what the deployment's filesystem lets a push do, measured in a scratch directory
 * that is gone again afterwards.
 *
 * **What a test can pin is the shape and the tidiness, not the host's answers.** A developer's disk
 * renames a directory with a file open in it and leaves no `.nfs` stray; the live host is the one
 * whose answers are worth having, and they are read there. So these tests assert that every line is
 * present, that a local filesystem gives the answers POSIX promises, and above all that the probe
 * leaves nothing behind — a probe that littered the deployment would be worse than none.
 *
 * The signed half — the verb, the serial a real probe spends and a dry run does not — is
 * {@link ApiTest}'s.
 */
#[CoversClass(FilesystemProbe::class)]
#[CoversClass(ProbeReport::class)]
#[CoversClass(UpdateProbe::class)]
#[CoversClass(ApplyManifest::class)]
#[CoversClass(Deployment::class)]
#[CoversClass(HealthFact::class)]
#[CoversClass(HealthSection::class)]
#[CoversClass(PlainTextResponse::class)]
#[CoversClass(Answer::class)]
#[CoversClass(TextBody::class)]
final class ProbeTest extends TestCase
{
    /** Every line a probe that ran answers, in the order it answers them. */
    private const array LINES = [
        'scratch',
        'devices',
        'free space',
        'file rename',
        'directory rename',
        'with a file open',
        'over an open file',
        'onto an empty dir',
        'onto a full dir',
        'swap window',
        'hard link',
        'symbolic link',
        'left behind',
    ];

    private string $sandbox = '';

    /**
     * A sandbox deployment per test, with its webroot, and nothing else in it.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/phpanta-probe-' . bin2hex(random_bytes(6));
        self::assertTrue(new Directory($this->sandbox . '/public')->create());
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->sandbox !== '') {
            chmod($this->sandbox, 0o755);
            UpdateFixture::removeTree($this->sandbox);
        }
    }

    /**
     * A probe answers every line, in order, and the deployment is exactly as it was.
     *
     * @return void
     */
    public function testAProbeAnswersEveryLineAndLeavesNothingBehind(): void
    {
        $report = $this->probe()->run(true);
        $body   = $report->render();

        self::assertTrue($report->isClean(), $body);
        self::assertSame(self::LINES, self::names($body));
        self::assertMatchesRegularExpression('/^  left behind +nothing$/m', $body);
        self::assertSame(['public'], $this->entries(), 'the probe left something in the deployment');
    }

    /**
     * On a local disk the renames do what POSIX says, and the two lines a swap turns on read as
     * numbers rather than refusals.
     *
     * The `.nfs` counts are not asserted beyond being there: nought on a local disk, and whatever the
     * host says on a shared one, which is the point of asking.
     *
     * @return void
     */
    public function testOnALocalDiskTheRenamesDoWhatPosixSays(): void
    {
        $body = $this->probe()->run(true)->render();

        foreach (
            [
                '/^  file rename +yes$/m',
                '/^  directory rename +yes, in \d+ µs$/m',
                '/^  with a file open +yes; the open file still reads, and \d+ \.nfs/m',
                '/^  over an open file +yes; the open file still reads the old bytes; /m',
                '/^  onto an empty dir +yes$/m',
                // POSIX refuses a rename onto a directory that holds anything.
                '/^  onto a full dir +no — .+$/m',
                '/^  swap window +\d+ µs with nothing at the name, \d+ µs for both renames$/m',
                '/^  devices +webroot on the same device; .+ on (the same|another) device$/m',
                '/^  free space +\d+ MiB free of \d+ MiB$/m',
            ] as $pattern
        ) {
            self::assertMatchesRegularExpression($pattern, $body);
        }
    }

    /**
     * A dry run names the directory it would have used and writes nothing at all.
     *
     * @return void
     */
    public function testADryRunNamesItsDirectoryAndWritesNothing(): void
    {
        $report = $this->probe()->run(false);
        $body   = $report->render();

        self::assertTrue($report->isClean());
        self::assertStringContainsString('a dry run, so nothing was written or measured', $body);
        self::assertStringContainsString($this->sandbox . '/.update-probe-' . getmypid() . '-', $body);
        self::assertSame(['scratch'], self::names($body));
        self::assertSame(['public'], $this->entries());
    }

    /**
     * A deployment the probe cannot make its directory in is reported, and a 500 — never thrown.
     *
     * @return void
     */
    public function testADeploymentItCannotWriteIntoIsReportedRatherThanThrown(): void
    {
        self::assertTrue(chmod($this->sandbox, 0o555));

        if (is_writable($this->sandbox)) {
            self::markTestSkipped('this process can write to a read-only directory');
        }

        $report = $this->probe()->run(true);

        self::assertFalse($report->isClean());
        self::assertStringContainsString('could not be created — ', $report->render());
        self::assertSame(['scratch'], self::names($report->render()));

        $response = new UpdateProbe(ApplyManifest::parse('{"apply":true}', 'probe'), $this->probe())->handle();

        self::assertSame(HttpStatusCode::InternalServerError, UpdateFixture::statusOf($response));
    }

    /**
     * The handler answers with the report, is a write exactly when it applies, and a dry run is a
     * 200 too.
     *
     * @return void
     */
    public function testTheHandlerAnswersWithTheReport(): void
    {
        $real = new UpdateProbe(ApplyManifest::parse('{"apply":true}', 'probe'), $this->probe());
        $dry  = new UpdateProbe(ApplyManifest::parse('{"apply":false,"mirror":true}', 'probe'), $this->probe());

        self::assertTrue($real->isWrite());
        self::assertFalse($dry->isWrite(), 'a dry run would spend a serial');

        $answered = $real->handle();
        self::assertSame(HttpStatusCode::Ok, UpdateFixture::statusOf($answered));
        self::assertStringContainsString('left behind', UpdateFixture::bodyOf($answered));

        $planned = $dry->handle();
        self::assertSame(HttpStatusCode::Ok, UpdateFixture::statusOf($planned));
        self::assertStringContainsString('dry run', UpdateFixture::bodyOf($planned));
    }

    /**
     * A malformed probe manifest is refused in the probe's own words, not the rollback's.
     *
     * @return void
     */
    public function testAMalformedProbeManifestIsRefusedInItsOwnWords(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('the probe manifest must carry apply:bool');

        (void) ApplyManifest::parse('{}', 'probe');
    }

    /**
     * Each probe gets a directory of its own, beside the roots, named for the process that made it.
     *
     * @return void
     */
    public function testEachProbeWorksInADirectoryOfItsOwn(): void
    {
        $deployment = new Deployment(new Directory($this->sandbox), new Directory($this->sandbox . '/public'));
        $first      = $deployment->probeDirectory()->path;

        self::assertStringStartsWith($this->sandbox . '/.update-probe-' . getmypid() . '-', $first);
        self::assertNotSame($first, $deployment->probeDirectory()->path);
    }

    /**
     * A probe that can reach nothing but the sandbox.
     *
     * @return FilesystemProbe
     */
    private function probe(): FilesystemProbe
    {
        return new FilesystemProbe(new Deployment(
            new Directory($this->sandbox),
            new Directory($this->sandbox . '/public'),
        ));
    }

    /**
     * What is in the sandbox, `.` and `..` aside.
     *
     * @return list<string>
     */
    private function entries(): array
    {
        return array_values(array_diff((array) scandir($this->sandbox), ['.', '..']));
    }

    /**
     * The name of every line under the report's caption, in order.
     *
     * @param string $body
     * @return list<string>
     */
    private static function names(string $body): array
    {
        preg_match_all('/^  (\S.*?) {2,}/m', $body, $matches);

        return $matches[1];
    }
}
