<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\CredentialFile;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\CapabilityAction;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Model\Api\ApiEnvelope;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Health\PhpSetting;
use Phpanta\Service\Api\CapabilityDeployment;
use Phpanta\Service\Api\CapabilityErrors;
use Phpanta\Service\Api\CapabilityExtensions;
use Phpanta\Service\Api\CapabilityRuntime;
use Phpanta\Service\Api\CapabilitySettings;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `/api/capability/v1/*`: what this host says about itself, with no verdict on any of it.
 *
 * Most of what is worth asserting is **completeness rather than value**. What the live host's
 * `memory_limit` is, is the answer's business; that every directive the engine knows has a line, and
 * every extension it loaded, is what a test can pin — and it is the whole difference between an
 * inventory and a curated list.
 *
 * The error log's branches and the deployment's are the ones the live host takes and a developer's
 * machine does not — an empty `error_log`, a `DOCUMENT_ROOT` that resolves — so both sides of both
 * are driven here, by turning the two pieces of global state the handlers read.
 */
#[CoversClass(CapabilityAction::class)]
#[CoversClass(CapabilityRuntime::class)]
#[CoversClass(CapabilityExtensions::class)]
#[CoversClass(CapabilitySettings::class)]
#[CoversClass(CapabilityDeployment::class)]
#[CoversClass(CapabilityErrors::class)]
#[CoversClass(HealthFact::class)]
#[CoversClass(HealthSection::class)]
#[CoversClass(PhpSetting::class)]
final class CapabilityTest extends TestCase
{
    /** The caption of the section that is present only when there is a log to quote. */
    private const string LOG = 'error log';

    private string $sandbox = '';
    private string $errorLog = '';
    private string $documentRoot = '';

    /**
     * Remembers the two pieces of global state these tests turn, so tearDown can put them back.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->errorLog     = (string) ini_get('error_log');
        $this->documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        $this->sandbox      = sys_get_temp_dir() . '/phpanta-capability-' . bin2hex(random_bytes(6));

        new Directory($this->sandbox)->create();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);

        if ($this->documentRoot === '') {
            unset($_SERVER['DOCUMENT_ROOT']);
        } else {
            $_SERVER['DOCUMENT_ROOT'] = $this->documentRoot;
        }

        if ($this->sandbox !== '') {
            UpdateFixture::removeTree($this->sandbox);
        }
    }

    // ───────────────────────────── the actions ─────────────────────────────

    /**
     * Every action is a read on GET, and answers one 200 whose text ends in a newline.
     *
     * @param CapabilityAction $action
     * @return void
     */
    #[DataProvider('actionProvider')]
    public function testEveryActionIsAReadAnsweringOnGet(CapabilityAction $action): void
    {
        $handler  = $action->handler(self::verified('/admin/capability/v1/' . $action->value));
        $response = $handler->handle();

        self::assertSame(HttpMethod::Get, $action->method());
        self::assertFalse($handler->isWrite(), 'an inventory that changes nothing must not spend a serial');
        self::assertSame(HttpStatusCode::Ok, UpdateFixture::statusOf($response));
        self::assertStringEndsWith("\n", UpdateFixture::bodyOf($response));
    }

    /**
     * @return iterable<string, array{CapabilityAction}>
     */
    public static function actionProvider(): iterable
    {
        foreach (CapabilityAction::cases() as $action) {
            yield $action->value => [$action];
        }
    }

    /**
     * Each action is answered by the handler named for it.
     *
     * @return void
     */
    public function testEachActionIsAnsweredByItsOwnHandler(): void
    {
        $verified = self::verified('/admin/capability/v1/runtime');

        self::assertInstanceOf(CapabilityRuntime::class, CapabilityAction::Runtime->handler($verified));
        self::assertInstanceOf(CapabilityExtensions::class, CapabilityAction::Extensions->handler($verified));
        self::assertInstanceOf(CapabilitySettings::class, CapabilityAction::Settings->handler($verified));
        self::assertInstanceOf(CapabilityDeployment::class, CapabilityAction::Deployment->handler($verified));
        self::assertInstanceOf(CapabilityErrors::class, CapabilityAction::Errors->handler($verified));
    }

    // ───────────────────────────── runtime ─────────────────────────────

    /**
     * The interpreter and the host, the clock and its zone.
     *
     * @return void
     */
    public function testTheRuntimeNamesTheInterpreterAndTheHost(): void
    {
        $body = self::body(new CapabilityRuntime()->handle());

        self::assertStringStartsWith("interpreter\n", $body);
        self::assertStringContainsString("\n\nhost\n", $body);
        self::assertMatchesRegularExpression('/^  version +' . preg_quote(PHP_VERSION, '/') . '$/m', $body);
        self::assertMatchesRegularExpression('/^  version id +' . PHP_VERSION_ID . '$/m', $body);
        self::assertMatchesRegularExpression('/^  ' . preg_quote(PhpSetting::Timezone->value, '/') . ' +\S/m', $body);
        self::assertMatchesRegularExpression('/^  clock +\d{4}-\d\d-\d\dT/m', $body);
    }

    // ───────────────────────────── extensions ─────────────────────────────

    /**
     * Every extension the engine loaded has a line, under the section its kind belongs in.
     *
     * @return void
     */
    public function testEveryLoadedExtensionHasItsOwnLine(): void
    {
        $body       = self::body(new CapabilityExtensions()->handle());
        $zendAt     = strpos($body, "\n\nzend extensions\n");
        $extensions = substr($body, 0, (int) $zendAt);
        $zend       = substr($body, (int) $zendAt);

        self::assertNotFalse($zendAt, 'the Zend extensions have no section of their own');

        foreach (get_loaded_extensions() as $name) {
            self::assertMatchesRegularExpression('/^  ' . preg_quote($name, '/') . ' +\S/m', $extensions, $name);
        }

        foreach (get_loaded_extensions(true) as $name) {
            self::assertMatchesRegularExpression('/^  ' . preg_quote($name, '/') . ' +\S/m', $zend, $name);
        }
    }

    // ───────────────────────────── settings ─────────────────────────────

    /**
     * Every directive the engine knows has exactly its own line, in one column.
     *
     * Asserted against the exact line rather than a pattern, so the column is part of it: every
     * value starts where the longest name's does.
     *
     * @return void
     */
    public function testEveryDirectiveHasItsOwnLineInOneColumn(): void
    {
        $body       = self::body(new CapabilitySettings()->handle());
        $directives = ini_get_all(null, false);
        $column     = HealthFact::COLUMN;

        foreach ($directives as $directive => $value) {
            $column = max($column, strlen($directive) + 1);
        }

        foreach ($directives as $directive => $value) {
            $value = (string) $value;

            self::assertStringContainsString(
                "\n  " . str_pad($directive, $column) . ' ' . ($value === '' ? '-' : $value) . "\n",
                $body,
                $directive,
            );
        }

        self::assertSame(count($directives) + 1, substr_count($body, "\n"), 'a line per directive, and the caption');
    }

    // ───────────────────────────── deployment ─────────────────────────────

    /**
     * A webroot that resolves is reported as the directory it resolves to.
     *
     * @return void
     */
    public function testAResolvableWebrootIsReported(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = App::current()->above()->path . '/public';

        self::assertStringContainsString(
            App::current()->above()->path . '/public',
            self::body(new CapabilityDeployment()->handle()),
        );
    }

    /**
     * One that does not resolve is reported as the refusal, and the rest still renders — not a 422.
     *
     * @return void
     */
    public function testAWebrootThatWillNotResolveIsReportedRatherThanThrown(): void
    {
        unset($_SERVER['DOCUMENT_ROOT']);

        $body = self::body(new CapabilityDeployment()->handle());

        self::assertStringContainsString('DOCUMENT_ROOT is not set', $body);
        self::assertStringContainsString(CredentialFile::Admin->value, $body, 'the rest of the section still renders');
    }

    /**
     * Whether a file is there and whether the repository carries it are reported side by side,
     * for every file the site reads — tracked or not, which is what separates this from `health`.
     *
     * @return void
     */
    public function testEachDataFileReportsBothItsPresenceAndItsTracking(): void
    {
        $body = self::body(new CapabilityDeployment()->handle());

        foreach (App::current()->dataFiles() as $file) {
            self::assertMatchesRegularExpression(
                '/^  ' . preg_quote($file->value, '/') . ' +(present  \d+|absent)  \('
                . ($file->isTracked() ? '' : 'un') . 'tracked\)$/m',
                $body,
            );
        }
    }

    /**
     * The framework has a line of its own, saying where it is deployed or that it is not yet — which
     * is what the first push that carries it is checked by.
     *
     * @return void
     */
    public function testTheFrameworkReportsWhereItIsDeployed(): void
    {
        $body      = self::body(new CapabilityDeployment()->handle());
        $framework = App::current()->above()->directory('phpanta');

        self::assertMatchesRegularExpression(
            '/^  phpanta +' . preg_quote($framework->exists() ? $framework->path : 'absent', '/') . '$/m',
            $body,
        );
    }

    // ───────────────────────────── errors ─────────────────────────────

    /**
     * The errors section names the mask, the three directives, and the last diagnostic.
     *
     * @return void
     */
    public function testTheErrorsSectionNamesWhereADiagnosticGoes(): void
    {
        $body = self::body(new CapabilityErrors()->handle());

        self::assertStringStartsWith("errors\n", $body);

        $names = [
            'reporting',
            PhpSetting::DisplayErrors->value,
            PhpSetting::LogErrors->value,
            PhpSetting::ErrorLog->value,
            'last',
        ];

        foreach ($names as $name) {
            self::assertMatchesRegularExpression('/^  ' . preg_quote($name, '/') . ' +\S/m', $body, $name);
        }
    }

    /**
     * With no destination configured there is no log section, which is the live host's own state.
     *
     * @return void
     */
    public function testWithNoDestinationThereIsNoLogSection(): void
    {
        ini_set('error_log', '');

        self::assertStringNotContainsString(self::LOG, self::body(new CapabilityErrors()->handle()));
    }

    /**
     * A destination naming nothing says so, rather than being absent like the case above.
     *
     * @return void
     */
    public function testADestinationThatIsNotThereSaysSo(): void
    {
        ini_set('error_log', $this->sandbox . '/nowhere.log');

        self::assertStringContainsString('no file there to read', $this->log());
    }

    /**
     * A readable log is quoted, newest lines last, and never more than the tail.
     *
     * @return void
     */
    public function testAReadableLogIsQuotedToItsTail(): void
    {
        $log   = new File($this->sandbox . '/php.log');
        $lines = [];

        for ($i = 1; $i <= 25; $i++) {
            $lines[] = 'line ' . $i;
        }

        self::assertTrue($log->write(implode("\n", $lines) . "\n"));
        ini_set('error_log', $log->path);

        $section = $this->log();

        self::assertStringContainsString('last 20 of 25 lines', $section);
        self::assertStringContainsString("\n  line 25", $section);
        self::assertStringContainsString("\n  line 6", $section);
        self::assertStringNotContainsString("\n  line 5\n", $section, 'the tail is 20 lines, not 21');
    }

    /**
     * An empty log is reported as empty rather than as twenty lines that are not there.
     *
     * @return void
     */
    public function testAnEmptyLogPromisesNothing(): void
    {
        $log = new File($this->sandbox . '/empty.log');

        self::assertTrue($log->write(''));
        ini_set('error_log', $log->path);

        self::assertStringContainsString('0 bytes, last 0 of 0 lines', $this->log());
    }

    /**
     * A log over the cap is quoted from its end: the last lines are there, the first are not, and
     * the header says how much was read rather than promising a line count it never took.
     *
     * 9000 lines of 35 bytes is 315,000 — past the quarter-megabyte, so the read starts mid-file
     * and the line it starts in is dropped rather than quoted cut through.
     *
     * @return void
     */
    public function testALogOverTheCapIsQuotedFromItsEnd(): void
    {
        $log   = new File($this->sandbox . '/huge.log');
        $lines = [];

        for ($i = 1; $i <= 9000; $i++) {
            $lines[] = sprintf('padding padding padding line %05d', $i);
        }

        self::assertTrue($log->write(implode("\n", $lines) . "\n"));
        ini_set('error_log', $log->path);

        $section = $this->log();

        self::assertStringContainsString('315000 bytes, last 20 lines, read from its final 262144', $section);
        self::assertStringContainsString("\n  padding padding padding line 09000", $section);
        self::assertStringContainsString("\n  padding padding padding line 08981", $section);
        self::assertStringNotContainsString('line 08980', $section, 'the tail is 20 lines, not 21');
        self::assertStringNotContainsString('line 00001', $section);
    }

    /**
     * A log that is there and cannot be read says so, rather than reading as an empty one.
     *
     * @return void
     */
    public function testALogThatCannotBeReadSaysSo(): void
    {
        $log = new File($this->sandbox . '/locked.log');

        self::assertTrue($log->write("a line\n"));
        chmod($log->path, 0o000);
        ini_set('error_log', $log->path);

        try {
            if (is_readable($log->path)) {
                self::markTestSkipped('this process can read a file with no permissions');
            }

            self::assertStringContainsString('there, but not readable by this process', $this->log());
        } finally {
            chmod($log->path, 0o600);
        }
    }

    // ───────────────────────────── fixtures ─────────────────────────────

    /**
     * What the gate would hand an action for a GET of $path.
     *
     * @param string $path
     * @return VerifiedRequest
     */
    private static function verified(string $path): VerifiedRequest
    {
        $manifest = (string) json_encode([
            'serial' => time(),
            'method' => HttpMethod::Get->value,
            'path'   => $path,
            'digest' => hash('sha256', ''),
            'size'   => 0,
        ], JSON_THROW_ON_ERROR);

        return new VerifiedRequest(ApiEnvelope::parse($manifest), $manifest, '');
    }

    /**
     * @param ApiResult $result
     * @return string
     */
    private static function body(ApiResult $result): string
    {
        return UpdateFixture::bodyOf($result);
    }

    /**
     * The error log's section: its caption and everything after it.
     *
     * @return string
     */
    private function log(): string
    {
        $body  = self::body(new CapabilityErrors()->handle());
        $start = strpos($body, "\n\n" . self::LOG . "\n");

        self::assertNotFalse($start, 'the answer has no ' . self::LOG . ' section');

        return substr($body, $start);
    }
}
