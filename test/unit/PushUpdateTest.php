<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use ArrayObject;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\TarArchive;
use Phpanta\Support\TarEntry;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Command\PushUpdate;
use Phpanta\Tool\Http\Request;
use Phpanta\Tool\Http\Response;
use Phpanta\Tool\Http\Transport;
use PHPUnit\Framework\TestCase;

/**
 * What `push-update` packs, in which order, and what it refuses to pack.
 *
 * The sites here are plain directories rather than git repositories — {@link FrameworkCheckoutTest}
 * owns that question — so the framework check has nothing to compare and passes, and what is left
 * is the payload itself, read back out of the request the fake transport recorded.
 *
 * No `#[CoversClass]`, like every other test of the tooling.
 */
final class PushUpdateTest extends TestCase
{
    private string $sandbox = '';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/phpanta-push-' . bin2hex(random_bytes(6));
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
     * **Each name once, and the stamped manifest last.**
     *
     * The server writes in packed order and keeps a repeated name's first position, so a manifest
     * packed from the working tree and again from `build/dist/` landed with `src/`, before the
     * webroot whose stamped URLs it names.
     *
     * @return void
     */
    public function testEachNameIsPackedOnceAndTheStampedManifestLast(): void
    {
        $site = $this->site();

        [$code, $error, $sent] = $this->push($site, '--dry-run');

        self::assertSame(ExitCode::Success, $code, $error);
        self::assertCount(1, $sent);

        $files = TarArchive::parse((string) gzdecode($sent[0]->body()))
            ->where(static fn(TarEntry $entry): bool => !$entry->isDirectory);
        $names = $files->map(static fn(TarEntry $entry): string => $entry->name)->toValues();

        self::assertSame(
            1,
            count(array_keys($names, 'src/Acme/AssetManifest.php', true)),
            'the manifest is packed more than once',
        );
        self::assertSame('src/Acme/AssetManifest.php', $names[count($names) - 1], 'the manifest is not last');
        self::assertSame(
            '<?php // stamped',
            $files->first(static fn(TarEntry $entry): bool => $entry->name === 'src/Acme/AssetManifest.php')?->contents,
        );
        self::assertContains('src/Acme/Page.php', $names, 'a working-tree file dist does not replace went missing');
    }

    /**
     * A tree that packs nothing is refused before anything is signed or sent.
     *
     * @return void
     */
    public function testATreeThatPacksNothingIsRefusedBeforeSending(): void
    {
        $site = $this->site();
        self::assertTrue($site->file('src/Acme/Page.php')->delete());
        self::assertTrue($site->file('src/Acme/AssetManifest.php')->delete());

        [$code, $error, $sent] = $this->push($site, '--dry-run');

        self::assertSame(ExitCode::Usage, $code);
        self::assertStringContainsString('src/ packs no files', $error);
        self::assertCount(0, $sent);
    }

    /**
     * A site with a framework in it, a manifest in both trees, and a built webroot.
     *
     * @return Directory
     */
    private function site(): Directory
    {
        $site = new Directory($this->sandbox . '/site');

        self::assertTrue($site->directory('phpanta/src')->create());
        self::assertTrue($site->file('phpanta/autoload.php')->write("<?php\n"));
        self::assertTrue($site->file('phpanta/src/App.php')->write("<?php\n"));

        self::assertTrue($site->directory('src/Acme')->create());
        self::assertTrue($site->file('src/Acme/Page.php')->write("<?php\n"));
        self::assertTrue($site->file('src/Acme/AssetManifest.php')->write('<?php // debug'));
        self::assertTrue($site->file('autoload.php')->write("<?php\n"));

        self::assertTrue($site->directory('build/dist/public')->create());
        self::assertTrue($site->directory('build/dist/src/Acme')->create());
        self::assertTrue($site->file('build/dist/public/index.php')->write("<?php\n"));
        self::assertTrue($site->file('build/dist/src/Acme/AssetManifest.php')->write('<?php // stamped'));

        return $site;
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
