<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\TarArchive;
use Phpanta\Support\TarEntry;
use Phpanta\Tool\Cli\UsageException;
use Phpanta\Tool\Update\PackedFile;
use Phpanta\Tool\Update\TarWriter;
use PHPUnit\Framework\TestCase;

/**
 * What the push packs: every file under a tree, readable back by the server's reader, the same bytes
 * every time — and never a file it could not read, packed as nothing.
 *
 * No `#[CoversClass]`, like every other test of the tooling.
 */
final class TarWriterTest extends TestCase
{
    /** Where a ustar header keeps its mtime, and how long the field is. */
    private const int MTIME_OFFSET = 136;
    private const int MTIME_LENGTH = 12;

    private string $sandbox = '';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/phpanta-tar-' . bin2hex(random_bytes(6));
        self::assertTrue(new Directory($this->sandbox)->create());
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
     * What is packed is what the server's reader reads back, name for name and byte for byte.
     *
     * @return void
     */
    public function testAPackedTreeReadsBackThroughTheServersReader(): void
    {
        $root = new Directory($this->sandbox . '/public');
        self::assertTrue($root->directory('assets/css')->create());
        self::assertTrue($root->file('index.php')->write("<?php\n"));
        self::assertTrue($root->file('.htaccess')->write("# dotfiles too\n"));
        self::assertTrue($root->file('assets/css/style.css')->write(str_repeat('a', 700)));

        $files = TarArchive::parse(TarWriter::pack(TarWriter::tree($root, 'public')))
            ->where(static fn(TarEntry $entry): bool => !$entry->isDirectory);

        self::assertSame(
            ['public/.htaccess', 'public/assets/css/style.css', 'public/index.php'],
            $files->map(static fn(TarEntry $entry): string => $entry->name)->toValues(),
        );
        $style = $files->first(static fn(TarEntry $entry): bool => $entry->name === 'public/assets/css/style.css');

        self::assertSame(str_repeat('a', 700), $style?->contents);
    }

    /**
     * A directory whose path reads as a glob pattern is walked like any other — a glob walk listed
     * nothing under it, and a push of nothing is a push the server mirrors as "delete all of it".
     *
     * @return void
     */
    public function testAPathShapedLikeAPatternIsWalked(): void
    {
        $root = new Directory($this->sandbox . '/site [draft]*');
        self::assertTrue($root->directory('{a,b}')->create());
        self::assertTrue($root->file('{a,b}/page.php')->write("<?php\n"));

        self::assertSame(
            ['src/{a,b}/page.php'],
            TarWriter::tree($root, 'src')->map(static fn(PackedFile $file): string => $file->name)->toValues(),
        );
    }

    /**
     * A file that cannot be read is refused rather than packed as the empty string, which the server
     * would write over its own copy.
     *
     * @return void
     */
    public function testAFileThatCannotBeReadIsRefused(): void
    {
        $root = new Directory($this->sandbox . '/public');
        self::assertTrue($root->create());
        self::assertTrue(symlink($this->sandbox . '/nowhere', $root->path . '/dangling.css'));

        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('cannot be packed');

        (void) TarWriter::tree($root, 'public');
    }

    /**
     * The same files make the same bytes, and every header's mtime is the epoch.
     *
     * @return void
     */
    public function testAnArchiveIsAFunctionOfItsFiles(): void
    {
        $files = new Collection(PackedFile::class)->with(
            new PackedFile('src/App.php', "<?php\n"),
            new PackedFile('public/index.php', "<?php\n"),
        );

        $archive = TarWriter::pack($files);

        self::assertSame($archive, TarWriter::pack($files));

        for ($offset = 0; $offset < strlen($archive); $offset += 512) {
            $block = substr($archive, $offset, 512);

            if (trim($block, "\0") === '' || substr($block, 257, 5) !== 'ustar') {
                continue;
            }

            self::assertSame(
                str_repeat('0', self::MTIME_LENGTH - 1) . "\0",
                substr($block, self::MTIME_OFFSET, self::MTIME_LENGTH),
                'a header carries a real mtime',
            );
        }
    }
}
