<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use ArrayObject;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Command\PushUpdate;
use Phpanta\Tool\Http\Request;
use Phpanta\Tool\Http\Response;
use Phpanta\Tool\Http\Transport;
use Phpanta\Tool\Update\FrameworkCheckout;
use PHPUnit\Framework\TestCase;

/**
 * `push-update` ships the framework out of the working tree, so it refuses one no commit of the site
 * reproduces — and these are real git repositories, a framework and a site vendoring it as a
 * submodule, because the question is git's and a fake would only answer what it was told to.
 *
 * No `#[CoversClass]`, like every other test of the tooling.
 */
final class FrameworkCheckoutTest extends TestCase
{
    private string $sandbox = '';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/phpanta-checkout-' . bin2hex(random_bytes(6));
        new Directory($this->sandbox)->create();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->sandbox !== '') {
            UpdateFixture::removeTree($this->sandbox);
        }
    }

    /**
     * The framework the site records, checked out and untouched, ships.
     *
     * @return void
     */
    public function testTheRecordedCheckoutPasses(): void
    {
        self::assertNull(new FrameworkCheckout($this->site())->refusal());
    }

    /**
     * A clone that never initialised the submodule has an empty `phpanta/`.
     *
     * @return void
     */
    public function testAFrameworkThatIsNotCheckedOutIsRefused(): void
    {
        $site = $this->site();
        $this->git($site->path, 'submodule', 'deinit', '-q', '--force', 'phpanta');

        self::assertStringContainsString('not checked out', (string) new FrameworkCheckout($site)->refusal());
    }

    /**
     * An edit made in place and never committed exists nowhere but here — and is named.
     *
     * @return void
     */
    public function testAnEditThatIsNotCommittedIsRefused(): void
    {
        $site = $this->site();
        self::assertTrue($site->directory('phpanta')->directory('src')->file('Thing.php')->write("<?php // edited\n"));

        $refusal = (string) new FrameworkCheckout($site)->refusal();

        self::assertStringContainsString('not committed', $refusal);
        self::assertStringContainsString('src/Thing.php', $refusal);
    }

    /**
     * The push packs every file under `src/`, tracked or not, so an untracked one is a change too.
     *
     * @return void
     */
    public function testAnUntrackedFileIsRefused(): void
    {
        $site = $this->site();
        self::assertTrue($site->directory('phpanta')->directory('src')->file('Stray.php')->write("<?php\n"));

        self::assertStringContainsString('src/Stray.php', (string) new FrameworkCheckout($site)->refusal());
    }

    /**
     * A framework commit the site has not recorded is refused until the site records it.
     *
     * @return void
     */
    public function testACommitTheSiteHasNotRecordedIsRefusedUntilItIs(): void
    {
        $site  = $this->site();
        $thing = $site->directory('phpanta')->directory('src')->file('Thing.php');
        self::assertTrue($thing->write("<?php // moved on\n"));
        $this->git($site->path . '/phpanta', 'commit', '-q', '-a', '-m', 'the framework moves on');

        self::assertStringContainsString('records', (string) new FrameworkCheckout($site)->refusal());

        $this->git($site->path, 'commit', '-q', '-a', '-m', 'the site records it');

        self::assertNull(new FrameworkCheckout($site)->refusal());
    }

    /**
     * A framework copied in rather than vendored as a submodule has nothing to be compared with.
     *
     * @return void
     */
    public function testAFrameworkThatIsNotASubmodulePasses(): void
    {
        $site = new Directory($this->sandbox . '/copied');
        self::assertTrue($site->create());
        self::assertTrue($site->directory('phpanta')->create());
        self::assertTrue($site->directory('phpanta')->file('autoload.php')->write("<?php\n"));

        self::assertNull(new FrameworkCheckout($site)->refusal());
    }

    /**
     * The command refuses before it signs or sends anything, dry run or not — and `--any-framework`
     * is the way past.
     *
     * @return void
     */
    public function testPushUpdateRefusesBeforeSendingAndTheFlagShipsItAnyway(): void
    {
        $site = $this->site();
        self::assertTrue($site->directory('phpanta')->directory('src')->file('Thing.php')->write("<?php // edited\n"));

        [$code, $error, $sent] = $this->push($site, '--dry-run');

        self::assertSame(ExitCode::Failure, $code);
        self::assertStringContainsString('not committed', $error);
        self::assertStringContainsString('--any-framework', $error);
        self::assertCount(0, $sent);

        [$code, , $sent] = $this->push($site, '--any-framework');

        self::assertSame(ExitCode::Success, $code);
        self::assertCount(1, $sent);
    }

    /**
     * A framework in a git repository of its own, and a site that vendors it at `phpanta/` and has
     * committed the submodule.
     *
     * @return Directory The site.
     */
    private function site(): Directory
    {
        $framework = new Directory($this->sandbox . '/framework');
        self::assertTrue($framework->directory('src')->create());
        self::assertTrue($framework->file('autoload.php')->write("<?php\n"));
        self::assertTrue($framework->directory('src')->file('Thing.php')->write("<?php\n"));
        $this->git($framework->path, 'init', '-q');
        $this->git($framework->path, 'add', '.');
        $this->git($framework->path, 'commit', '-q', '-m', 'the framework');

        $site = new Directory($this->sandbox . '/site');
        self::assertTrue($site->directory('src')->create());
        self::assertTrue($site->file('autoload.php')->write("<?php\n"));
        self::assertTrue($site->directory('src')->file('Page.php')->write("<?php\n"));
        $this->git($site->path, 'init', '-q');
        $this->git($site->path, 'submodule', 'add', '-q', $framework->path, 'phpanta');
        $this->git($site->path, 'add', '.');
        $this->git($site->path, 'commit', '-q', '-m', 'the site');

        return $site;
    }

    /**
     * Runs git in $in, with an identity and nothing from the machine's configuration that could
     * change the answer; fails the test if git does.
     *
     * @param string $in
     * @param string ...$arguments
     * @return void
     */
    private function git(string $in, string ...$arguments): void
    {
        $process = proc_open(
            [
                'git', '-C', $in,
                '-c', 'user.name=Phpanta', '-c', 'user.email=test@phpanta.invalid',
                '-c', 'commit.gpgsign=false', '-c', 'init.defaultBranch=master',
                '-c', 'protocol.file.allow=always',
                ...$arguments,
            ],
            [1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertNotFalse($process);

        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), 'git ' . implode(' ', $arguments) . ': ' . $error);
    }

    /**
     * Runs `push-update` against $site with a transport that answers 200 and records what it sent.
     *
     * @param Directory $site
     * @param string ...$flags
     * @return array{ExitCode, string, ArrayObject<int, Request>}
     */
    private function push(Directory $site, string ...$flags): array
    {
        $dist = $site->directory('build')->directory('dist');
        self::assertTrue($dist->directory('public')->create());
        self::assertTrue($dist->directory('src')->create());
        self::assertTrue($dist->directory('public')->file('index.php')->write("<?php\n"));

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key, 'this host cannot generate an EC key, so nothing below is meaningful');
        self::assertTrue(openssl_pkey_export($key, $pem));
        $keyFile = new File($this->sandbox . '/update.key');
        self::assertTrue($keyFile->write((string) $pem, 0o600));

        /** @var ArrayObject<int, Request> $sent */
        $sent      = new ArrayObject();
        $transport = new readonly class ($sent) implements Transport {
            /**
             * @param ArrayObject<int, Request> $sent
             */
            public function __construct(private ArrayObject $sent) {}

            /**
             * @param Request $request
             * @return Response
             */
            public function send(Request $request): Response
            {
                $this->sent->append($request);

                return new Response(200, '');
            }
        };

        $out   = fopen('php://memory', 'r+');
        $error = fopen('php://memory', 'r+');

        $code = Runner::execute(
            new PushUpdate($site, 'https://phpanta.invalid', '.config/phpanta/update.key', $transport),
            ['--key', $keyFile->path, ...$flags],
            new Output($out, $error),
        );

        rewind($error);

        return [$code, (string) stream_get_contents($error), $sent];
    }
}
