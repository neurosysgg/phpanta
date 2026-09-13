<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\App;
use Phpanta\Exception\UpdateException;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Model\Update\Deployment;
use Phpanta\Model\Update\UpdateFile;
use Phpanta\Model\Update\UpdateManifest;
use Phpanta\Model\Update\UpdateReport;
use Phpanta\Model\Update\UpdateRoot;
use Phpanta\Service\Api\UpdatePatch;
use Phpanta\Service\UpdateApplier;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\TarArchive;
use Phpanta\Support\TarEntry;
use Phpanta\Support\TarMemberType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The update service: what it will write, what it refuses, and what it says about either.
 *
 * **Everything here is past the signature**, which is the line this file was split on when
 * `/update` became `/api/update/v1/patch`. The site's own API suite owns the half that refuses in silence —
 * the credential, the envelope, the gate, and the property that an unsigned caller cannot tell any
 * of it from a typo. What is left is the half that refuses *out loud*, so these tests assert the
 * sentence, because past the signature the sentence is the only account of the run that exists.
 *
 * That split is why {@link UpdatePatch} appears here and {@link \Phpanta\Controller\ApiController}
 * does not: the handler is the applier's answer turned into a response, and it needs no credential
 * to be asked for one.
 *
 * The archive fixtures come from {@link UpdateFixture}, which builds raw ustar bytes rather than
 * going through `TarWriter` — that writer, deliberately, cannot produce a symlink, a device node or
 * a name with `..` in it, so a fixture built by it could only exercise the refusals that do not
 * matter.
 */
#[CoversClass(UpdateApplier::class)]
#[CoversClass(UpdatePatch::class)]
#[CoversClass(UpdateFile::class)]
#[CoversClass(UpdateManifest::class)]
#[CoversClass(UpdateReport::class)]
#[CoversClass(UpdateRoot::class)]
#[CoversClass(Deployment::class)]
#[CoversClass(TarArchive::class)]
#[CoversClass(TarEntry::class)]
#[CoversClass(TarMemberType::class)]
final class UpdateTest extends TestCase
{
    private string $sandbox = '';

    /**
     * A sandbox deployment per test.
     *
     * No key and no serial file, which is the whole difference between this file and the site's
     * own API suite: nothing here is reached through a signature, so nothing here needs one.
     * {@link UpdateApplier} takes its {@link Deployment} as a constructor argument precisely so a
     * test cannot reach the live tree rather than being unlikely to.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/phpanta-update-' . bin2hex(random_bytes(6));
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
     * Every member shape the endpoint will not write, refused by name.
     *
     * @param string $name
     * @param string $type
     * @param string $expected A fragment of the sentence the refusal must carry.
     * @return void
     */
    #[DataProvider('refusedMemberProvider')]
    public function testTheApplierRefusesAMemberItWillNotWrite(string $name, string $type, string $expected): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        (void) $this->applier()->apply(
            (string) gzencode(UpdateFixture::member($name, $type === TarMemberType::File->value ? 'x' : '', $type)
                . str_repeat("\0", UpdateFixture::BLOCK * 2)),
            self::manifest(),
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedMemberProvider(): iterable
    {
        $file = TarMemberType::File->value;

        yield 'a data/ path'      => ['data/admin.php', $file, 'under none of the roots'];
        yield 'an unknown root'   => ['vendor/autoload.php', $file, 'under none of the roots'];
        yield 'a traversal'       => ['src/../../etc/passwd', $file, 'walks the tree'];
        yield 'an absolute path'  => ['/etc/passwd', $file, 'not a plain relative path'];
        yield 'a backslash path'  => ['public\\..\\evil.php', $file, 'not a plain relative path'];
        yield 'a symlink'         => ['public/evil.php', TarMemberType::Symlink->value, 'a symlink'];
        yield 'a hardlink'        => ['public/evil.php', TarMemberType::Hardlink->value, 'a hardlink'];
        yield 'a character device' => ['public/evil.php', TarMemberType::CharacterDevice->value, 'a character device'];
        yield 'a block device'    => ['public/evil.php', TarMemberType::BlockDevice->value, 'a block device'];
        yield 'a fifo'            => ['public/evil.php', TarMemberType::Fifo->value, 'a fifo'];
        yield 'a long-name record' => ['././@LongLink', TarMemberType::LongName->value, 'a GNU long-name record'];
        yield 'a pax header'      => ['pax_global_header', TarMemberType::PaxGlobal->value, 'a pax global header'];

        // Two shapes where the *name* is fine and the pair of name-and-kind is not. Neither is
        // something `tar` produces and neither is reachable without the private key — they are
        // refused because the alternative is a destination computed from them.
        //
        // A tree root matches its own name as well as anything under it, which is right for the
        // directory entry `tar` writes for `public/` and wrong for a regular file called `public`:
        // Deployment::destination() strips the prefix and one separator, so that resolves to the
        // webroot directory itself. Nothing would be overwritten — rename() refuses a directory —
        // but it would have been reported as a write that failed rather than a payload never legal.
        yield 'a tree root as a file' => ['public', $file, 'a tree this push writes into'];
        yield 'the other tree root'   => ['src', $file, 'a tree this push writes into'];

        // And a regular file whose name is written as a directory, which is what keeps the name
        // check() validates and the name UpdateFile carries the same string.
        yield 'a file named as a dir' => ['public/x/', $file, 'written as a directory'];
    }

    /**
     * The single-file root is *not* caught by the rule above, which is the whole reason that rule
     * asks {@link \Phpanta\Model\Update\UpdateRoot::isTree()}.
     *
     * `autoload.php` is a member whose name is exactly its root, and it is the one legitimate case
     * of that — a push that could not carry it would be a push that cannot replace the autoloader.
     *
     * @return void
     */
    public function testTheSingleFileRootIsStillWritableUnderItsOwnName(): void
    {
        $report = $this->applier()->apply(
            (string) gzencode(UpdateFixture::member('autoload.php', '<?php // x')
                . str_repeat("\0", UpdateFixture::BLOCK * 2)),
            self::manifest(),
        );

        self::assertTrue($report->isComplete(), $report->render());
    }

    /**
     * A header whose checksum does not match its own bytes is refused before its name is believed.
     *
     * @return void
     */
    public function testABadChecksumIsRefused(): void
    {
        $bad = substr_replace(UpdateFixture::member('public/x.php', 'x'), 'AAAAAAAA', 148, 8);

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/not an octal number/');

        (void) $this->applier()->apply(
            (string) gzencode($bad . str_repeat("\0", UpdateFixture::BLOCK * 2)),
            self::manifest(),
        );
    }

    /**
     * A body that is not gzip at all.
     *
     * @return void
     */
    public function testAnArchiveThatIsNotGzipIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/not gzip/');

        (void) $this->applier()->apply('X', self::manifest());
    }

    /**
     * An archive that declares more bytes than it carries.
     *
     * @return void
     */
    public function testATruncatedArchiveIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/truncated/');

        TarArchive::parse(UpdateFixture::member('public/x.php', str_repeat('x', 4096), sizeOverride: 999_999));
    }

    /**
     * A member of a type no tar defines at all.
     *
     * @return void
     */
    public function testAnUndefinedMemberTypeIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/a type no tar defines/');

        TarArchive::parse(UpdateFixture::member('public/x.php', '', 'Z') . str_repeat("\0", UpdateFixture::BLOCK * 2));
    }

    // ───────────────────────────── the manifest ─────────────────────────────

    /**
     * Every field is required and typed; there are no defaults.
     *
     * A missing `mirror` defaulting to false is an update that quietly stops deleting; a missing
     * `apply` defaulting to true is a dry run that was not one. Both are silent, so neither is
     * allowed to happen.
     *
     * @param string $json
     * @return void
     */
    #[DataProvider('badManifestProvider')]
    public function testAMalformedManifestIsRefused(string $json): void
    {
        $this->expectException(UpdateException::class);

        UpdateManifest::parse($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badManifestProvider(): iterable
    {
        yield 'not JSON'      => ['{'];
        yield 'not an object' => ['"a string"'];
        yield 'no apply'      => ['{"mirror":true}'];
        yield 'no mirror'     => ['{"apply":true}'];
        yield 'apply as int'  => ['{"apply":1,"mirror":true}'];
        yield 'mirror as string' => ['{"apply":true,"mirror":"yes"}'];

        // The envelope's fields are not this parser's business and their absence is not an error
        // here — ApiEnvelope reads the same bytes and refuses them there. A row asserting the
        // opposite is how one document quietly acquires two owners for one field.
        yield 'the envelope\'s fields alone' => ['{"serial":1,"method":"POST","path":"/x"}'];
    }

    /**
     * `data` is not a root, which is the single rule keeping the credentials and a site's own data safe.
     *
     * @return void
     */
    public function testDataIsNotARoot(): void
    {
        self::assertNull(UpdateRoot::of('data/admin.php'));
        self::assertNull(UpdateRoot::of('data/posts.php'));
        self::assertNull(UpdateRoot::of('data'));
        self::assertSame(
            ['public', 'src', 'autoload.php', 'phpanta'],
            array_column(UpdateRoot::cases(), 'value'),
        );
    }

    /**
     * A tree root claims its own directory entry as well as what is under it.
     *
     * An archive names `public/` before it names `public/index.php`, and a version of this that
     * matched only the prefix refused every well-formed payload at its first member.
     *
     * @return void
     */
    public function testATreeRootClaimsItsOwnDirectoryEntry(): void
    {
        self::assertSame(UpdateRoot::Public, UpdateRoot::of('public'));
        self::assertSame(UpdateRoot::Public, UpdateRoot::of('public/index.php'));
        self::assertSame(UpdateRoot::Source, UpdateRoot::of('src'));
        self::assertSame(UpdateRoot::Autoload, UpdateRoot::of('autoload.php'));
        self::assertSame(UpdateRoot::Framework, UpdateRoot::of('phpanta'));
        self::assertSame(UpdateRoot::Framework, UpdateRoot::of('phpanta/src/App.php'));
        self::assertSame(UpdateRoot::Framework, UpdateRoot::of('phpanta/autoload.php'));
        self::assertNull(UpdateRoot::of('phpantasm/x.php'), 'a prefix is a whole segment, not a string');
        self::assertFalse(UpdateRoot::Autoload->isTree(), 'a single-file root has no tree to mirror');
        self::assertTrue(UpdateRoot::Public->isTree());
        self::assertTrue(UpdateRoot::Source->isTree());
        self::assertTrue(UpdateRoot::Framework->isTree());
    }

    /**
     * A name that merely starts with a root's letters is not under that root.
     *
     * @return void
     */
    public function testAPrefixIsNotARoot(): void
    {
        self::assertNull(UpdateRoot::of('publicity/x.php'));
        self::assertNull(UpdateRoot::of('srcs/x.php'));
        self::assertNull(UpdateRoot::of('autoload.php.bak'));
    }

    // ───────────────────────────── the key ─────────────────────────────

    /**
     * The report copies rather than accumulating, and says which run it describes.
     *
     * @return void
     */
    public function testTheReportCopies(): void
    {
        $empty = new UpdateReport();
        $one   = $empty->wrote('public/a.js');

        self::assertTrue($empty->isComplete());
        self::assertStringNotContainsString('public/a.js', $empty->render());
        self::assertStringContainsString('+ public/a.js', $one->render());
        self::assertStringContainsString('applied', $one->render());
        self::assertStringContainsString('dry run', $one->dryRun()->render());
        self::assertFalse($one->failed('public/b.js', 'nope')->isComplete());
    }

    // ───────────────────────────── the round trip ─────────────────────────────

    /**
     * A push writes what it carries, removes what it omits, and touches nothing else.
     *
     * @return void
     */
    public function testAPushMirrorsTheTree(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->directory('assets')->create());
        self::assertTrue($webroot->file('assets/stale.js')->write('stale'));
        self::assertTrue($webroot->file('keep.txt')->write('old'));

        $report = $this->applier()->apply(
            UpdateFixture::archive(['public/keep.txt' => 'new']),
            self::manifest(mirror: true),
        );

        self::assertTrue($report->isComplete(), $report->render());
        self::assertSame('new', $webroot->file('keep.txt')->read());
        self::assertFalse($webroot->file('assets/stale.js')->exists(), 'the mirror left a stale file behind');
    }

    /**
     * **A root the push does not carry is left exactly as it is**, dry run and real run alike.
     *
     * The push from a clone whose submodule was never checked out carries no `phpanta/`, and a
     * mirror that read that silence as "the framework should be empty" took the framework off the
     * server — `/api` with it, so only a full deploy could put it back. What the push says nothing
     * about, the mirror does nothing to, and the report says so in a sentence.
     *
     * @return void
     */
    public function testAPushLeavesAloneEveryRootItDoesNotCarry(): void
    {
        $framework = new Directory($this->sandbox . '/phpanta/src');
        self::assertTrue($framework->create());
        self::assertTrue($framework->file('App.php')->write('<?php // deployed'));

        $source = new Directory($this->sandbox . '/src');
        self::assertTrue($source->create());
        self::assertTrue($source->file('Blog.php')->write('<?php // deployed'));

        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());
        self::assertTrue($webroot->file('stale.js')->write('stale'));

        $payload = ['public/index.php' => '<?php // new'];

        $planned = $this->applier()->apply(
            UpdateFixture::archive($payload),
            self::manifest(apply: false, mirror: true),
        );
        self::assertStringNotContainsString('- phpanta/src/App.php', $planned->render());
        self::assertStringNotContainsString('- src/Blog.php', $planned->render());
        self::assertStringContainsString('- public/stale.js', $planned->render());
        self::assertStringContainsString('note: phpanta/ is not in this push', $planned->render());

        $report = $this->applier()->apply(UpdateFixture::archive($payload), self::manifest(mirror: true));

        self::assertTrue($report->isComplete(), $report->render());
        self::assertSame(
            '<?php // deployed',
            $framework->file('App.php')->read(),
            'the mirror deleted a root the push did not carry',
        );
        self::assertSame('<?php // deployed', $source->file('Blog.php')->read());
        self::assertFalse($webroot->file('stale.js')->exists(), 'the root the push did carry was not mirrored');
        self::assertStringContainsString('note: src/ is not in this push', $report->render());
    }

    /**
     * A run that could not write everything deletes nothing.
     *
     * The mirror removes the old half of a change on the understanding that the new half is there,
     * and a failed write is the case where it is not.
     *
     * @return void
     */
    public function testTheMirrorDoesNotRunAfterAWriteFailed(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());
        self::assertTrue($webroot->file('blocked')->write('in the way'));
        self::assertTrue($webroot->file('stale.txt')->write('still needed'));

        $report = $this->applier()->apply(
            UpdateFixture::archive(['public/fine.txt' => 'x', 'public/blocked/deep.txt' => 'y']),
            self::manifest(mirror: true),
        );

        self::assertFalse($report->isComplete());
        self::assertTrue($webroot->file('stale.txt')->exists(), 'the mirror ran after a write failed');
        self::assertStringContainsString('the mirror did not run, because a write failed', $report->render());
    }

    /**
     * A mirror under a path a glob would read as a pattern still sees what is there.
     *
     * The walk was a `glob()`, so a deployment under `[…]` listed nothing — and while a mirror that
     * sees nothing deletes nothing, the writer's matching walk packed nothing, which is a push the
     * mirror reads as "delete all of it".
     *
     * @return void
     */
    public function testTheMirrorReadsATreeWhosePathLooksLikeAPattern(): void
    {
        $above   = new Directory($this->sandbox . '/with [brackets] *');
        $webroot = $above->directory('public');
        self::assertTrue($webroot->create());
        self::assertTrue($webroot->file('stale.js')->write('stale'));

        $report = new UpdateApplier(new Deployment($above, $webroot))->apply(
            UpdateFixture::archive(['public/keep.txt' => 'new']),
            self::manifest(mirror: true),
        );

        self::assertTrue($report->isComplete(), $report->render());
        self::assertFalse($webroot->file('stale.js')->exists(), 'the mirror could not see under a pattern-shaped path');
    }

    /**
     * A name that is a file and a directory of another member is refused before anything is written.
     *
     * @return void
     */
    public function testAFileThatIsAlsoTheDirectoryOfAnotherIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage("'public/a' as a file and as the directory 'public/a/b' is under");

        (void) $this->applier()->apply(
            UpdateFixture::archive(['public/a' => 'x', 'public/a/b' => 'y']),
            self::manifest(),
        );
    }

    /**
     * An archive cut off at a block boundary is refused, not read as a smaller tree.
     *
     * @return void
     */
    public function testAnArchiveWithNoEndBlockIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('without an end-of-archive block');

        TarArchive::parse(UpdateFixture::member('public/x.php', 'x'));
    }

    /**
     * A partial block after the last member is the same fault a few bytes on.
     *
     * @return void
     */
    public function testATrailingPartialBlockIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('without an end-of-archive block');

        TarArchive::parse(UpdateFixture::member('public/x.php', 'x') . 'abc');
    }

    /**
     * Past the marker there is padding and nothing else.
     *
     * @return void
     */
    public function testBytesAfterTheEndBlockAreRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('bytes after its end-of-archive block');

        TarArchive::parse(
            UpdateFixture::member('public/x.php', 'x') . str_repeat("\0", UpdateFixture::BLOCK * 2) . 'trailing',
        );
    }

    /**
     * An archive that expands past the cap is refused rather than decoded until the process dies.
     *
     * @return void
     */
    public function testAnArchiveThatExpandsPastTheCapIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('expands past');

        (void) $this->applier()->apply(
            (string) gzencode(str_repeat("\0", UpdateApplier::MAX_EXPANDED + 1)),
            self::manifest(),
        );
    }

    /**
     * A root the push carries but the deployment does not have yet has nothing to sweep, and a dry
     * run over it plans no deletion.
     *
     * @return void
     */
    public function testACarriedRootNotYetOnDiskPlansNoDeletion(): void
    {
        self::assertFalse(new Directory($this->sandbox . '/src')->exists());

        $planned = $this->applier()->apply(
            UpdateFixture::archive(['src/New.php' => '<?php // new']),
            self::manifest(apply: false, mirror: true),
        );

        self::assertTrue($planned->isComplete(), $planned->render());
        self::assertStringNotContainsString("\n- ", $planned->render());
        self::assertFalse(new Directory($this->sandbox . '/src')->exists(), 'a dry run created a directory');
    }

    /**
     * The mirror never deletes *through* a symlink.
     *
     * A push cannot introduce one — {@link TarArchive} refuses the member type — so a symlink under
     * a root was placed by something outside this endpoint. Were the walk to follow it, a link to a
     * tree outside the roots would have that tree read as surplus and unlinked a file at a time. The
     * walk treats a link as a leaf instead: the link is what is weighed against the payload, and the
     * target it points at is never entered, listed, or removed. This is the one mirror hazard a test
     * can only reach by planting on disk what an archive is forbidden to carry.
     *
     * @return void
     */
    public function testTheMirrorDoesNotDeleteThroughASymlink(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());

        // A directory outside every root, holding a file a push must never be able to reach.
        $outside = new Directory($this->sandbox . '/outside');
        self::assertTrue($outside->create());
        self::assertTrue($outside->file('secret.txt')->write('untouchable'));

        // …reached from inside the webroot only through a symlink someone would have had to plant.
        $link = $this->sandbox . '/public/link';
        self::assertTrue(symlink($outside->path, $link), 'this platform cannot make a symlink');

        try {
            $report = $this->applier()->apply(
                UpdateFixture::archive(['public/keep.txt' => 'new']),
                self::manifest(mirror: true),
            );

            self::assertTrue(
                $outside->file('secret.txt')->exists(),
                'the mirror followed the symlink and deleted a file outside the roots',
            );
            self::assertSame('untouchable', $outside->file('secret.txt')->read());
            self::assertSame('new', $webroot->file('keep.txt')->read(), 'the payload file was disturbed');
        } finally {
            @unlink($link);
        }
    }

    /**
     * A dry run reports the same plan and writes none of it.
     *
     * @return void
     */
    public function testADryRunWritesNothing(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());
        self::assertTrue($webroot->file('keep.txt')->write('old'));

        $report = $this->applier()->apply(
            UpdateFixture::archive(['public/keep.txt' => 'new']),
            self::manifest(apply: false),
        );

        self::assertStringContainsString('dry run', $report->render());
        self::assertStringContainsString('+ public/keep.txt', $report->render());
        self::assertSame('old', $webroot->file('keep.txt')->read(), 'a dry run wrote to disk');
    }

    /**
     * A file the payload does not change is not rewritten, and the dry run says so first.
     *
     * **The assertion that matters is the mtime**, not the count. Rewriting an identical file is
     * harmless on every filesystem but one a shared host may well serve off — NFS — and
     * {@link File::write()} renames its temp file onto the target, so rewriting `public/index.php`
     * while the request executes out of it silly-renames the open inode aside as `.nfsXXXXXXXX`.
     * The mirror then meets that stray in the same request and cannot delete it — one undeletable
     * file in the webroot and one spurious failure, per push. The first real push to production did
     * exactly that, which is why this asserts the file was left strictly alone rather than merely
     * that a counter said so.
     *
     * @return void
     */
    public function testAnUnchangedFileIsNotRewritten(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());
        self::assertTrue($webroot->file('same.txt')->write('identical'));
        self::assertTrue($webroot->file('other.txt')->write('old'));

        $stamp = filemtime($webroot->file('same.txt')->path);
        self::assertIsInt($stamp);
        touch($webroot->file('same.txt')->path, $stamp - 60);
        clearstatcache();

        $payload = ['public/same.txt' => 'identical', 'public/other.txt' => 'new'];

        $planned = $this->applier()->apply(UpdateFixture::archive($payload), self::manifest(apply: false));
        self::assertStringContainsString('unchanged 1', $planned->render());
        self::assertStringContainsString('+ public/other.txt', $planned->render());
        self::assertStringNotContainsString('+ public/same.txt', $planned->render());

        $report = $this->applier()->apply(UpdateFixture::archive($payload), self::manifest());

        self::assertTrue($report->isComplete(), $report->render());
        self::assertStringContainsString('written 1  unchanged 1', $report->render());
        self::assertSame('new', $webroot->file('other.txt')->read());
        self::assertSame('identical', $webroot->file('same.txt')->read());

        clearstatcache();
        self::assertSame(
            $stamp - 60,
            filemtime($webroot->file('same.txt')->path),
            'an unchanged file was rewritten, which on NFS strands the old inode as a .nfs file',
        );
    }

    // ───────────────────────────── the last refusals ─────────────────────────────

    /**
     * A header whose stored checksum does not match its bytes is refused, and says why.
     *
     * The checksum is the only thing saying a 512-byte block *is* a header rather than the middle
     * of somebody's file. Without it a corrupt archive is not an error but an archive that appears
     * to hold different members, which is the difference between refusing bytes and writing the
     * wrong ones.
     *
     * @return void
     */
    public function testAHeaderThatDoesNotMatchItsChecksumIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('does not match its own checksum');

        // The name is changed after the checksum was computed over the original, so the block is
        // well formed in every other way — which is the case worth refusing.
        $member = UpdateFixture::member('public/a.txt', 'x');

        TarArchive::parse(substr_replace($member, 'X', 0, 1) . str_repeat("\0", UpdateFixture::BLOCK * 2));
    }

    /**
     * A member name that is empty or longer than ustar allows is refused before anything is written.
     *
     * @param string $name
     * @param string $prefix
     * @return void
     */
    #[DataProvider('unwritableNameProvider')]
    public function testAMemberNameThatIsEmptyOrTooLongIsRefused(string $name, string $prefix): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('empty or over 255 bytes');

        // Built by hand rather than through archive(): a name this shape is one TarWriter would
        // never produce, which is exactly why the reader has to have an opinion about it. The
        // discard is deliberate and says so — apply() carries #[NoDiscard] because its report is
        // the endpoint's whole response, and what is being demonstrated here is that it throws.
        (void) $this->applier()->apply(
            (string) gzencode(
                UpdateFixture::member($name, 'x', prefix: $prefix) . str_repeat("\0", UpdateFixture::BLOCK * 2),
            ),
            self::manifest(),
        );
    }

    /**
     * @return iterable
     */
    public static function unwritableNameProvider(): iterable
    {
        // A name of only slashes rtrims to nothing, which is the shape a directory member takes
        // when everything before the slash has already been stripped.
        yield 'empty'      => ['', ''];
        yield 'only slash' => ['/', ''];

        // 155 + '/' + 100 is 256, one past the bound — and it takes both ustar name fields to say
        // it, which is why the reader joining them is worth a test of its own.
        yield 'too long'   => [str_repeat('a', 100), str_repeat('p', 155)];
    }

    /**
     * A dry run says what the mirror would remove, and removes none of it.
     *
     * The mirror is the half of a push that deletes, so predicting it is the half of `--dry-run`
     * worth having. A run that reported only what it would write would be silent about the one
     * operation that cannot be undone.
     *
     * @return void
     */
    public function testADryRunReportsWhatTheMirrorWouldRemove(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->directory('assets')->create());
        self::assertTrue($webroot->file('assets/stale.js')->write('stale'));

        $report = $this->applier()->apply(
            UpdateFixture::archive(['public/keep.txt' => 'new']),
            self::manifest(apply: false, mirror: true),
        );

        self::assertStringContainsString('dry run', $report->render());
        self::assertStringContainsString('- public/assets/stale.js', $report->render());
        self::assertStringContainsString('deleted 1', $report->render());
        self::assertTrue($webroot->file('assets/stale.js')->exists(), 'a dry run deleted a file');
        self::assertFalse($webroot->file('keep.txt')->exists(), 'a dry run wrote a file');
    }

    /**
     * A surplus file that cannot be removed is named rather than passed over.
     *
     * Mirroring is the operation with no second chance — the point of naming a failure here is that
     * the file is still on the server, still being served, and the report is the only place that
     * will ever say so.
     *
     * @return void
     */
    public function testASurplusFileThatCannotBeRemovedIsNamed(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->directory('locked')->create());
        self::assertTrue($webroot->file('locked/stale.js')->write('stale'));

        // unlink() needs write permission on the *directory*, not on the file.
        self::assertTrue(chmod($webroot->directory('locked')->path, 0o555));

        if (is_writable($webroot->directory('locked')->path)) {
            chmod($webroot->directory('locked')->path, 0o755);
            self::markTestSkipped('this process can write to a read-only directory');
        }

        try {
            $report = $this->applier()->apply(
                UpdateFixture::archive(['public/keep.txt' => 'new']),
                self::manifest(mirror: true),
            );

            self::assertFalse($report->isComplete());
            self::assertStringContainsString('! public/locked/stale.js', $report->render());
            self::assertStringContainsString('could not be removed', $report->render());
        } finally {
            chmod($webroot->directory('locked')->path, 0o755);
        }
    }

    // ───────────────────────────── the deployment ─────────────────────────────

    /**
     * The deployment a real request runs in comes from the booted app, and nowhere else.
     *
     * Asserted here rather than left to production because {@link Deployment::current()} is the one
     * constructor a test must never reach by accident — it is what resolves the *live* tree, and
     * the reason every applier in this file is handed a sandbox instead.
     *
     * @return void
     */
    public function testTheCurrentDeploymentIsResolvedFromConfig(): void
    {
        $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = App::current()->above()->path . '/public';

        try {
            $deployment = Deployment::current();

            self::assertSame(
                App::current()->above()->path . '/public',
                $deployment->directory(UpdateRoot::Public)?->path,
            );
            self::assertSame(
                App::current()->above()->path . '/src',
                $deployment->directory(UpdateRoot::Source)?->path,
            );
        } finally {
            if ($previous === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previous;
            }
        }
    }

    /**
     * The one root that is a file rather than a tree maps both ways without a directory.
     *
     * `autoload.php` has no tree under it, so it has nothing that can go stale and
     * {@link Deployment::directory()} answers null for it. Both halves of the name mapping have to
     * cope with that null, and they are written next to each other so the two cannot drift — which
     * is the whole reason `nameOf()` lives beside `destination()` rather than in the mirror.
     *
     * @return void
     */
    public function testTheAutoloadRootIsASingleFileInBothDirections(): void
    {
        $deployment = new Deployment(
            new Directory($this->sandbox),
            new Directory($this->sandbox . '/public'),
        );

        self::assertNull($deployment->directory(UpdateRoot::Autoload));

        self::assertSame(
            $this->sandbox . '/autoload.php',
            $deployment->destination(UpdateRoot::Autoload, 'autoload.php')->path,
        );

        self::assertSame(
            'autoload.php',
            $deployment->nameOf(UpdateRoot::Autoload, $this->sandbox . '/autoload.php'),
        );
    }

    /**
     * The framework's root lands beside `src/`, one level above the webroot, and maps back the same
     * way — and a deployment that has never been sent it has no directory, so nothing to mirror.
     *
     * @return void
     */
    public function testTheFrameworkRootLandsBesideTheSource(): void
    {
        $deployment = new Deployment(
            new Directory($this->sandbox),
            new Directory($this->sandbox . '/public'),
        );

        self::assertSame($this->sandbox . '/phpanta', $deployment->directory(UpdateRoot::Framework)?->path);
        self::assertFalse($deployment->directory(UpdateRoot::Framework)?->exists());

        self::assertSame(
            $this->sandbox . '/phpanta/src/App.php',
            $deployment->destination(UpdateRoot::Framework, 'phpanta/src/App.php')->path,
        );

        self::assertSame(
            'phpanta/src/App.php',
            $deployment->nameOf(UpdateRoot::Framework, $this->sandbox . '/phpanta/src/App.php'),
        );
    }

    // ───────────────────────────── the handler, past the gate ─────────────────────────────

    /**
     * An archive that verifies but will not expand throws, having written nothing.
     *
     * The signature is over the *manifest*, and the manifest vouches for the archive by digest —
     * so bytes that are not gzip at all can still be perfectly signed. What the caller then sees is
     * a 422 with this sentence in it, which is {@link \Phpanta\Controller\ApiController}'s to
     * build and the site's own API suite's to assert; what this file owns is that the handler refuses by
     * throwing rather than by answering, since a refusal that came back as a `Response` would be
     * indistinguishable from a push that ran.
     *
     * @return void
     */
    public function testAnArchiveThatWillNotExpandThrows(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/not gzip/');

        (void) new UpdatePatch(self::manifest(), 'this is not gzip at all', $this->applier())->handle();
    }

    /**
     * A push that could not write everything is a 500, and names what it could not write.
     *
     * The failure is manufactured the way it would actually happen — something is already in the
     * way — rather than by mocking a write. `public/blocked` is a regular file here, so the member
     * `public/blocked/deep.txt` needs a directory that cannot be made.
     *
     * @return void
     */
    public function testAPushThatCouldNotWriteEverythingIsAnswered500(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());
        self::assertTrue($webroot->file('blocked')->write('in the way'));

        $response = $this->respond(UpdateFixture::archive([
            'public/fine.txt'         => 'written',
            'public/blocked/deep.txt' => 'cannot be',
        ]));

        self::assertSame(HttpStatusCode::InternalServerError, UpdateFixture::statusOf($response));
        self::assertStringContainsString('! public/blocked/deep.txt', UpdateFixture::bodyOf($response));
        self::assertStringContainsString('directory could not be created', UpdateFixture::bodyOf($response));

        // The rest still landed. A partial push is reported as one rather than undone of its own
        // accord: the report names exactly what is missing, and taking the push back is
        // `update v1 rollback`, which is the operator's to ask for — see RollbackTest.
        self::assertSame('written', $webroot->file('fine.txt')->read());
    }

    /**
     * A member that cannot be written over is named, and the push is a 500.
     *
     * @return void
     */
    public function testAMemberThatCannotBeWrittenIsNamed(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());

        // A directory where the payload wants a file: File::write() renames its temp file onto the
        // target, and a rename over a non-empty directory cannot succeed.
        self::assertTrue($webroot->directory('occupied.txt')->create());
        self::assertTrue($webroot->file('occupied.txt/inside')->write('x'));

        $response = $this->respond(UpdateFixture::archive(['public/occupied.txt' => 'nope']));

        self::assertSame(HttpStatusCode::InternalServerError, UpdateFixture::statusOf($response));
        self::assertStringContainsString('! public/occupied.txt', UpdateFixture::bodyOf($response));
        self::assertStringContainsString('could not be written', UpdateFixture::bodyOf($response));
    }

    /**
     * An applier that can only reach the sandbox.
     *
     * **The important word is *only*.** A sandbox supplied through `$_SERVER['DOCUMENT_ROOT']`
     * reaches `App::webroot()`, whose job is to find the *real* deployment, and `src/` is not
     * redirected by it at all. Injecting the whole {@link Deployment} is what makes the live tree
     * unreachable from a test rather than unlikely.
     *
     * @return UpdateApplier
     */
    private function applier(): UpdateApplier
    {
        return new UpdateApplier(new Deployment(
            new Directory($this->sandbox),
            new Directory($this->sandbox . '/public'),
        ));
    }

    /**
     * @param bool $apply
     * @param bool $mirror
     * @return UpdateManifest
     */
    private static function manifest(bool $apply = true, bool $mirror = false): UpdateManifest
    {
        return UpdateManifest::parse(json_encode([
            'apply'  => $apply,
            'mirror' => $mirror,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The handler's answer to $archive, with an applier that can reach nothing but the sandbox.
     *
     * **It builds {@link UpdatePatch} directly rather than going through the controller**, and that
     * is the split rather than a shortcut: what these tests are about is the applier's report
     * becoming a response — a 422 for an archive that will not expand, a 500 for a run that could
     * not write everything — and none of that has anything to say about signatures. Reaching it
     * through {@link \Phpanta\Controller\ApiController} would mean minting a credential to test
     * the shape of a sentence. The site's own API suite takes the same path end to end, once, which is where
     * a claim about the wiring belongs.
     *
     * @param string $archive
     * @param UpdateApplier|null $applier
     * @param bool $apply
     * @param bool $mirror
     * @return PlainTextResponse
     */
    private function respond(
        string $archive,
        ?UpdateApplier $applier = null,
        bool $apply = true,
        bool $mirror = false,
    ): PlainTextResponse {
        $response = new UpdatePatch(
            self::manifest($apply, $mirror),
            $archive,
            $applier ?? $this->applier(),
        )->handle();

        self::assertInstanceOf(PlainTextResponse::class, $response);

        return $response;
    }
}
