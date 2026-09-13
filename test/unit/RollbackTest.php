<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Exception\UpdateException;
use Phpanta\Http\Answer;
use Phpanta\Http\Api\UpdateAction;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\TextBody;
use Phpanta\Model\Api\ApiEnvelope;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Update\ApplyManifest;
use Phpanta\Model\Update\Deployment;
use Phpanta\Model\Update\PreviousRelease;
use Phpanta\Model\Update\RecordEntry;
use Phpanta\Model\Update\RecordKind;
use Phpanta\Model\Update\RollbackStep;
use Phpanta\Model\Update\UpdateFile;
use Phpanta\Model\Update\UpdateManifest;
use Phpanta\Model\Update\UpdateReport;
use Phpanta\Model\Update\UpdateRoot;
use Phpanta\Service\Api\UpdatePatch;
use Phpanta\Service\Api\UpdateRollback;
use Phpanta\Service\ReleaseRecord;
use Phpanta\Service\UpdateApplier;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\TarArchive;
use Phpanta\Support\TarEntry;
use Phpanta\Support\TarMemberType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The record a push takes of the release it replaces, and the rollback that puts it back.
 *
 * **Every test runs against a sandbox {@link Deployment}**, for {@link UpdateTest}'s reason: the
 * applier takes it by constructor so a test cannot reach the live tree, and the record hangs off the
 * same deployment, so it cannot reach the live record either.
 *
 * Four properties, and everything here is one of them:
 *
 * - **The record is exactly what the push changes.** Saved: every file overwritten or mirrored
 *   away. Listed: every file added. Neither: a file whose bytes were already there, which is also
 *   left strictly alone — the NFS rule.
 * - **No record, no push.** A record that cannot be taken completely refuses the push before a
 *   live byte moves.
 * - **A rollback moves the tree from the push's state to the one before, and from nowhere else.** A
 *   path in neither state refuses the whole thing; a path already back is left alone; a completed
 *   rollback clears the record, so a second one refuses.
 * - **Nothing goes through a symbolic link** — not the record, not a saved copy, not a live path.
 *
 * The signed half — that `POST /api/update/v1/rollback` reaches this at all, spends a serial for a
 * write and none for a dry run — is {@link ApiTest}'s.
 */
#[CoversClass(UpdateApplier::class)]
#[CoversClass(ReleaseRecord::class)]
#[CoversClass(PreviousRelease::class)]
#[CoversClass(RecordEntry::class)]
#[CoversClass(RecordKind::class)]
#[CoversClass(RollbackStep::class)]
#[CoversClass(ApplyManifest::class)]
#[CoversClass(UpdateRollback::class)]
#[CoversClass(UpdatePatch::class)]
#[CoversClass(UpdateAction::class)]
#[CoversClass(UpdateManifest::class)]
#[CoversClass(UpdateReport::class)]
#[CoversClass(UpdateRoot::class)]
#[CoversClass(UpdateFile::class)]
#[CoversClass(Deployment::class)]
#[CoversClass(TarArchive::class)]
#[CoversClass(TarEntry::class)]
#[CoversClass(TarMemberType::class)]
#[CoversClass(ApiEnvelope::class)]
#[CoversClass(VerifiedRequest::class)]
#[CoversClass(PlainTextResponse::class)]
#[CoversClass(Answer::class)]
#[CoversClass(TextBody::class)]
final class RollbackTest extends TestCase
{
    /** Bytes a text comparison would get wrong: a NUL, a CRLF, a byte that is not UTF-8. */
    private const string OLD_BYTES = "old\0\r\nbytes\xff";

    private string $sandbox = '';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/phpanta-rollback-' . bin2hex(random_bytes(6));
        self::assertTrue(new Directory($this->sandbox . '/public')->create());
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

    // ───────────────────────────── the record ─────────────────────────────

    /**
     * A push saves what it overwrites and what the mirror deletes, lists what it adds, and neither
     * saves nor touches a file whose bytes were already there.
     *
     * @return void
     */
    public function testAPushRecordsWhatItReplacesAndNothingElse(): void
    {
        $this->plantTheFirstRelease();
        $stamp = $this->ageSame();

        $report = $this->pushTheSecondRelease(serial: 1757000000);

        self::assertTrue($report->isComplete(), $report->render());
        self::assertStringContainsString('recorded — 2 saved, 1 added', $report->render());

        $release = $this->record()->read();

        self::assertInstanceOf(PreviousRelease::class, $release);
        self::assertTrue($release->complete);
        self::assertSame(1757000000, $release->serial);
        self::assertSame(
            ['changed public/other.bin', 'added public/assets/fresh.js', 'deleted public/old/gone.js'],
            self::kinds($release),
        );

        $saved = new Directory($this->sandbox . '/.update-previous/saved/public');
        self::assertSame(self::OLD_BYTES, $saved->file('other.bin')->read(), 'the saved copy is not the old bytes');
        self::assertSame('gone', $saved->file('old/gone.js')->read());
        self::assertFalse($saved->file('same.txt')->exists(), 'an unchanged file was saved');
        self::assertFalse($saved->file('assets/fresh.js')->exists(), 'an added file was saved');

        clearstatcache();
        self::assertSame($stamp, filemtime($this->web()->file('same.txt')->path), 'an unchanged file was rewritten');
    }

    /**
     * A stray the NFS client left is never recorded, so no rollback can bring one back.
     *
     * @return void
     */
    public function testAnNfsStrayIsNeverRecorded(): void
    {
        $this->plantTheFirstRelease();
        self::assertTrue($this->web()->file('.nfs00000000bd2dac2512228f60')->write('an old inode'));

        $report = $this->pushTheSecondRelease();

        self::assertTrue($report->isComplete(), $report->render());
        self::assertSame(
            ['changed public/other.bin', 'added public/assets/fresh.js', 'deleted public/old/gone.js'],
            self::kinds($this->record()->read()),
        );

        (void) $this->applier()->rollback(true);

        self::assertFalse(
            $this->web()->file('.nfs00000000bd2dac2512228f60')->exists(),
            'a rollback brought a stray back',
        );
        $this->assertTheFirstReleaseIsLive();
    }

    /**
     * The handler hands the envelope's serial to the record, so a rollback can say which push it undoes.
     *
     * @return void
     */
    public function testThePatchHandlerRecordsItsSerial(): void
    {
        self::assertTrue($this->web()->file('a.txt')->write('old'));

        $response = new UpdatePatch(
            self::manifest(),
            UpdateFixture::archive(['public/a.txt' => 'new']),
            $this->applier(),
            1757000123,
        )->handle();

        self::assertSame(HttpStatusCode::Ok, UpdateFixture::statusOf($response));
        self::assertSame(1757000123, $this->record()->read()?->serial);
        self::assertStringContainsString(
            'rolling back the push of serial 1757000123',
            $this->applier()->rollback(false)->render(),
        );
    }

    /**
     * A record that cannot be taken refuses the push, and not one live file changes.
     *
     * @param string $how
     * @return void
     */
    #[DataProvider('unwritableRecordProvider')]
    public function testAPushWhoseRecordCannotBeWrittenChangesNothingLive(string $how): void
    {
        $this->plantTheFirstRelease();
        $record = new Directory($this->sandbox . '/.update-previous');

        match ($how) {
            'the record is a file' => self::assertTrue(new File($record->path)->write('in the way')),
            'the record cannot be written into' => self::assertTrue($record->create() && chmod($record->path, 0o500)),
            'a saved copy cannot be written' => self::assertTrue(
                $record->create() && $record->file('saved')->write('in the way'),
            ),
        };

        if ($how === 'the record cannot be written into' && is_writable($record->path)) {
            chmod($record->path, 0o700);
            self::markTestSkipped('this process can write to a read-only directory');
        }

        try {
            (void) $this->pushTheSecondRelease();
            self::fail('the push went ahead without a record');
        } catch (UpdateException $refused) {
            self::assertStringContainsString('could not be recorded, so nothing was written', $refused->getMessage());
        } finally {
            if ($record->exists()) {
                chmod($record->path, 0o700);
            }
        }

        $this->assertTheFirstReleaseIsLive();
        self::assertNull($this->record()->read(), 'a refused push left a record behind');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unwritableRecordProvider(): iterable
    {
        yield 'the record is a file'              => ['the record is a file'];
        yield 'the record cannot be written into' => ['the record cannot be written into'];
        yield 'a saved copy cannot be written'    => ['a saved copy cannot be written'];
    }

    /**
     * A file that is there and cannot be read is a file the record cannot keep, so the push is refused.
     *
     * @return void
     */
    public function testAPushThatWouldReplaceAnUnreadableFileIsRefused(): void
    {
        $locked = $this->web()->file('locked.txt');
        self::assertTrue($locked->write('old'));
        self::assertTrue(chmod($locked->path, 0o000));

        try {
            if ($locked->read() !== null) {
                self::markTestSkipped('this process reads a file whatever its mode (running as root?)');
            }

            (void) $this->applier()->apply(
                UpdateFixture::archive(['public/locked.txt' => 'new']),
                self::manifest(),
            );
            self::fail('a file the record could not read was replaced anyway');
        } catch (UpdateException $refused) {
            self::assertStringContainsString("'public/locked.txt' could not be read", $refused->getMessage());
        } finally {
            chmod($locked->path, 0o644);
        }

        self::assertSame('old', $locked->read());
    }

    /**
     * Running the same push twice does not cost the step back the first one earned.
     *
     * @return void
     */
    public function testAPushThatChangesNothingKeepsTheRecord(): void
    {
        self::assertTrue($this->web()->file('a.txt')->write('old'));

        (void) $this->push(['public/a.txt' => 'new'], mirror: false);
        $again = $this->push(['public/a.txt' => 'new'], mirror: false);

        self::assertStringContainsString(
            'changes nothing, so the record of the previous release was left as it is',
            $again->render(),
        );

        $report = $this->applier()->rollback(true);

        self::assertTrue($report->isComplete(), $report->render());
        self::assertSame('old', $this->web()->file('a.txt')->read());
    }

    /**
     * Each push replaces the record, and clearing the old one deletes exactly what its index lists.
     *
     * The stray is the assertion that matters: a file in the record directory that no index names is
     * not this class's to delete, however much it looks like one of its own.
     *
     * @return void
     */
    public function testOnlyTheLastReleaseIsKept(): void
    {
        self::assertTrue($this->web()->file('a.txt')->write('v1'));

        (void) $this->push(['public/a.txt' => 'v2', 'public/b.txt' => 'b'], mirror: false);

        $stray = new File($this->sandbox . '/.update-previous/saved/stray');
        self::assertTrue($stray->write('not listed'));

        (void) $this->push(['public/a.txt' => 'v3'], mirror: false);

        self::assertSame(
            ['changed public/a.txt'],
            $this->record()->read()?->entries->map(
                static fn(RecordEntry $entry): string => $entry->kind->value . ' ' . $entry->name,
            )->toValues(),
        );
        self::assertSame('v2', new File($this->sandbox . '/.update-previous/saved/public/a.txt')->read());
        self::assertSame('not listed', $stray->read(), 'the clear deleted a file no index listed');

        self::assertTrue($this->applier()->rollback(true)->isComplete());
        self::assertSame('v2', $this->web()->file('a.txt')->read(), 'the rollback went past one step');
        self::assertSame('b', $this->web()->file('b.txt')->read(), 'the rollback undid an earlier push');
    }

    // ───────────────────────────── the rollback ─────────────────────────────

    /**
     * A rollback restores what changed byte for byte, removes what was added, recreates what the
     * mirror deleted, leaves the unchanged alone, and clears the record — so a second one refuses.
     *
     * @return void
     */
    public function testARollbackRestoresTheReleaseItRecorded(): void
    {
        $this->plantTheFirstRelease();
        $stamp = $this->ageSame();
        (void) $this->pushTheSecondRelease();

        self::assertFalse($this->web()->directory('old')->exists(), 'the mirror did not sweep the emptied directory');

        $report = $this->applier()->rollback(true);
        $body   = $report->render();

        self::assertTrue($report->isComplete(), $body);
        self::assertStringStartsWith('applied', $body);
        self::assertStringContainsString('+ public/old/gone.js', $body);
        self::assertStringContainsString('+ public/other.bin', $body);
        self::assertStringContainsString('- public/assets/fresh.js', $body);
        self::assertLessThan(
            strpos($body, '+ public/other.bin'),
            strpos($body, '+ public/old/gone.js'),
            'what the mirror deleted was not recreated first',
        );
        self::assertStringContainsString('the record is cleared', $body);

        $this->assertTheFirstReleaseIsLive();
        self::assertFalse(
            $this->web()->directory('assets')->exists(),
            'the directory the push created was left behind',
        );

        clearstatcache();
        self::assertSame($stamp, filemtime($this->web()->file('same.txt')->path), 'an unchanged file was rewritten');
        self::assertNull($this->record()->read(), 'the record was not cleared');

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('there is no previous release to roll back to');

        (void) $this->applier()->rollback(true);
    }

    /**
     * A dry run reports the same plan, and changes nothing — the record included.
     *
     * @return void
     */
    public function testARollbackDryRunChangesNothing(): void
    {
        $this->plantTheFirstRelease();
        (void) $this->pushTheSecondRelease();

        $body = $this->applier()->rollback(false)->render();

        self::assertStringContainsString('dry run', $body);
        self::assertStringContainsString('+ public/other.bin', $body);
        self::assertStringContainsString('- public/assets/fresh.js', $body);

        $this->assertTheSecondReleaseIsLive();
        self::assertTrue($this->record()->read()?->complete, 'a dry run cleared the record');

        self::assertTrue($this->applier()->rollback(true)->isComplete(), 'the record did not survive its dry run');
        $this->assertTheFirstReleaseIsLive();
    }

    /**
     * With no record there is nothing to do, and the rollback says so rather than succeeding at it.
     *
     * @return void
     */
    public function testARollbackWithNoRecordIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('there is no previous release to roll back to');

        (void) $this->applier()->rollback(false);
    }

    /**
     * An incomplete record is one whose push was refused, so there is nothing to roll back.
     *
     * @return void
     */
    public function testARollbackRefusesAnIncompleteRecord(): void
    {
        $record = new Directory($this->sandbox . '/.update-previous');
        self::assertTrue($record->create());
        self::assertTrue($record->file('release')->write(new PreviousRelease(
            1,
            false,
            new Collection(RecordEntry::class)->with(RecordEntry::added(UpdateRoot::Public, 'public/a.txt', 'a')),
        )->render()));

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('is incomplete');

        (void) $this->applier()->rollback(true);
    }

    /**
     * A tree something else has changed since the push is refused whole, before anything is written.
     *
     * @return void
     */
    public function testARollbackRefusesADeploymentThatHasMovedOn(): void
    {
        $this->plantTheFirstRelease();
        (void) $this->pushTheSecondRelease();

        // A full deploy, or a hand edit, since the push.
        self::assertTrue($this->web()->file('other.bin')->write('third'));

        foreach ([false, true] as $apply) {
            try {
                (void) $this->applier()->rollback($apply);
                self::fail('a rollback mixed two releases');
            } catch (UpdateException $refused) {
                self::assertStringContainsString('Changed since: public/other.bin', $refused->getMessage());
            }
        }

        self::assertSame('third', $this->web()->file('other.bin')->read());
        self::assertSame('fresh', $this->web()->file('assets/fresh.js')->read(), 'a refused rollback removed a file');
        self::assertFalse($this->web()->file('old/gone.js')->exists(), 'a refused rollback restored a file');
        self::assertTrue($this->record()->read()?->complete, 'a refused rollback cleared the record');
    }

    /**
     * After a push that landed partway, a rollback restores what landed and leaves the rest alone.
     *
     * A staged push lands whole or refuses, except where a rename itself fails — a live directory
     * the process may not write into. The state that leaves is built here by hand: `other.txt` holds
     * its old bytes, as though its rename had never happened.
     *
     * @return void
     */
    public function testARollbackAfterAPartialPushRestoresOnlyWhatLanded(): void
    {
        self::assertTrue($this->web()->file('fine.txt')->write('old'));
        self::assertTrue($this->web()->file('other.txt')->write('old'));
        self::assertTrue($this->web()->file('stale.txt')->write('stale'));

        $pushed = $this->push(['public/fine.txt' => 'new', 'public/other.txt' => 'new'], mirror: false);
        self::assertTrue($pushed->isComplete(), $pushed->render());
        self::assertTrue($this->web()->file('other.txt')->write('old'));

        $report = $this->applier()->rollback(true);

        self::assertTrue($report->isComplete(), $report->render());
        self::assertStringContainsString('written 1  unchanged 1  deleted 0  failed 0', $report->render());
        self::assertSame('old', $this->web()->file('fine.txt')->read());
        self::assertSame('old', $this->web()->file('other.txt')->read());
        self::assertSame('stale', $this->web()->file('stale.txt')->read());
    }

    /**
     * A restore with something in its way refuses the rollback before anything is restored or
     * removed, and keeps the record; run again once the obstacle is gone, the same rollback finishes.
     *
     * @return void
     */
    public function testARestoreThatCannotBePlacedRefusesTheRollback(): void
    {
        $this->plantTheFirstRelease();
        (void) $this->pushTheSecondRelease();

        // Something in the way of the directory the mirror swept.
        self::assertTrue($this->web()->file('old')->write('in the way'));

        try {
            (void) $this->applier()->rollback(true);
            self::fail('a rollback that could not be staged ran');
        } catch (UpdateException $refused) {
            self::assertSame(
                'the rollback could not be staged, so nothing was restored or removed: public/old/gone.js —'
                . " 'public/old' is a file where it needs a directory",
                $refused->getMessage(),
            );
        }

        self::assertSame('new', $this->web()->file('other.bin')->read(), 'a refused rollback restored a file');
        self::assertSame('fresh', $this->web()->file('assets/fresh.js')->read(), 'a refused rollback removed a file');
        self::assertTrue($this->record()->read()?->complete);
        self::assertFalse(
            new Directory($this->sandbox . '/.update-stage')->exists(),
            'a refused rollback left its stage',
        );

        self::assertTrue($this->web()->file('old')->delete());

        $again = $this->applier()->rollback(true);

        self::assertTrue($again->isComplete(), $again->render());
        // Nothing was restored the first time, so both restores land now; same.txt was never recorded.
        self::assertStringContainsString('written 2  unchanged 0  deleted 1', $again->render());
        $this->assertTheFirstReleaseIsLive();
    }

    /**
     * A saved copy that is no longer the bytes it was recorded under refuses the rollback whole.
     *
     * @return void
     */
    public function testADamagedSavedCopyRefusesTheRollback(): void
    {
        $this->plantTheFirstRelease();
        (void) $this->pushTheSecondRelease();

        self::assertTrue(new File($this->sandbox . '/.update-previous/saved/public/other.bin')->write('truncated'));

        try {
            (void) $this->applier()->rollback(true);
            self::fail('a damaged copy was restored');
        } catch (UpdateException $refused) {
            self::assertStringContainsString('the saved copy of public/other.bin is missing', $refused->getMessage());
        }

        $this->assertTheSecondReleaseIsLive();
    }

    /**
     * An index this class did not write refuses the rollback, and blocks the next push — which
     * will not delete around a record it cannot enumerate.
     *
     * @return void
     */
    public function testAnUnreadableRecordRefusesTheRollbackAndThePush(): void
    {
        $this->plantTheFirstRelease();
        $index = new File($this->sandbox . '/.update-previous/release');
        self::assertTrue($index->directory()->create());
        self::assertTrue($index->write("garbage\n"));

        try {
            (void) $this->applier()->rollback(true);
            self::fail('an unreadable record was rolled back to');
        } catch (UpdateException $refused) {
            self::assertStringContainsString('does not open the way', $refused->getMessage());
        }

        try {
            (void) $this->pushTheSecondRelease();
            self::fail('a push deleted around a record it could not read');
        } catch (UpdateException $refused) {
            self::assertStringContainsString('cannot be enumerated, and nothing was deleted', $refused->getMessage());
        }

        $this->assertTheFirstReleaseIsLive();
        self::assertSame("garbage\n", $index->read());
    }

    // ───────────────────────────── symbolic links ─────────────────────────────

    /**
     * A record directory that is a link is never written through, and the push is refused.
     *
     * @return void
     */
    public function testARecordThatIsASymlinkRefusesThePush(): void
    {
        $this->plantTheFirstRelease();
        $outside = $this->outside();
        $link    = $this->sandbox . '/.update-previous';
        self::assertTrue(symlink($outside->path, $link), 'this platform cannot make a symlink');

        try {
            (void) $this->pushTheSecondRelease();
            self::fail('the record was written through a symlink');
        } catch (UpdateException $refused) {
            self::assertStringContainsString('symbolic link', $refused->getMessage());
        } finally {
            unlink($link);
        }

        $this->assertTheFirstReleaseIsLive();
        self::assertSame(['secret.txt'], self::namesIn($outside), 'the push wrote through the link');
    }

    /**
     * A saved copy's path through a link is refused too, and nothing lands where it points.
     *
     * @return void
     */
    public function testASavedCopyIsNeverWrittenThroughASymlink(): void
    {
        $this->plantTheFirstRelease();
        $outside = $this->outside();
        $saved   = new Directory($this->sandbox . '/.update-previous/saved');
        self::assertTrue($saved->create());
        self::assertTrue(symlink($outside->path, $saved->path . '/public'));

        try {
            (void) $this->pushTheSecondRelease();
            self::fail('a saved copy was written through a symlink');
        } catch (UpdateException $refused) {
            self::assertStringContainsString('symbolic link', $refused->getMessage());
        } finally {
            unlink($saved->path . '/public');
        }

        $this->assertTheFirstReleaseIsLive();
        self::assertSame(['secret.txt'], self::namesIn($outside), 'a copy landed through the link');
    }

    /**
     * A surplus link is neither followed nor kept, and a rollback neither reads through it nor
     * reasons about it.
     *
     * What the mirror makes of the link itself is the mirror's business and {@link UpdateTest}'s;
     * what this asserts is only that nothing behind it was read into the record or touched.
     *
     * @return void
     */
    public function testASurplusSymlinkIsNeitherFollowedNorKept(): void
    {
        $outside = $this->outside();
        $link    = $this->web()->path . '/link';
        self::assertTrue(symlink($outside->path, $link));

        try {
            $report = $this->push(['public/keep.txt' => 'new']);

            self::assertTrue($report->isComplete(), $report->render());
            self::assertStringContainsString('public/link is a symbolic link the payload omits', $report->render());
            self::assertSame(
                ['added public/keep.txt'],
                $this->record()->read()?->entries->map(
                    static fn(RecordEntry $entry): string => $entry->kind->value . ' ' . $entry->name,
                )->toValues(),
            );
            self::assertFalse(
                is_dir($this->sandbox . '/.update-previous/saved'),
                'something was saved from behind the link',
            );

            self::assertTrue($this->applier()->rollback(true)->isComplete());
            self::assertFalse($this->web()->file('keep.txt')->exists());
            self::assertSame(['secret.txt'], self::namesIn($outside), 'something behind the link changed');
            self::assertSame('untouchable', $outside->file('secret.txt')->read());
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
        }
    }

    /**
     * A saved copy reached through a link is not read, even when the bytes behind it are the right ones.
     *
     * @return void
     */
    public function testARollbackNeverReadsASavedCopyThroughASymlink(): void
    {
        $this->plantTheFirstRelease();
        (void) $this->pushTheSecondRelease();

        // The genuine bytes, moved outside and linked back: only the link check can refuse them.
        $saved   = $this->sandbox . '/.update-previous/saved/public';
        $outside = $this->outside();
        self::assertTrue($outside->file('other.bin')->write((string) new File($saved . '/other.bin')->read()));
        self::assertTrue(rename($saved, $this->sandbox . '/moved'));
        self::assertTrue(symlink($outside->path, $saved));

        try {
            (void) $this->applier()->rollback(true);
            self::fail('a saved copy was read through a symlink');
        } catch (UpdateException $refused) {
            self::assertStringContainsString('the saved copy of', $refused->getMessage());
        } finally {
            unlink($saved);
        }

        $this->assertTheSecondReleaseIsLive();
    }

    /**
     * A live path that has become a link since the push is a path in neither state.
     *
     * @return void
     */
    public function testARollbackRefusesALivePathThatIsNowASymlink(): void
    {
        $this->plantTheFirstRelease();
        (void) $this->pushTheSecondRelease();

        $fresh = $this->web()->file('assets/fresh.js');
        self::assertTrue($fresh->delete());
        self::assertTrue(symlink($this->outside()->file('secret.txt')->path, $fresh->path));

        try {
            (void) $this->applier()->rollback(true);
            self::fail('a rollback acted on a symlink');
        } catch (UpdateException $refused) {
            self::assertStringContainsString('Changed since: public/assets/fresh.js', $refused->getMessage());
        } finally {
            unlink($fresh->path);
        }

        self::assertSame('untouchable', $this->outside()->file('secret.txt')->read());
    }

    /**
     * A payload file whose destination is a link replaces the link, and the record lists the path
     * as added — never reading, saving or restoring what the link pointed at.
     *
     * @return void
     */
    public function testAPushOverASymlinkRecordsThePathAsAdded(): void
    {
        $outside = $this->outside();
        $target  = $this->web()->path . '/target.txt';
        self::assertTrue(symlink($outside->file('secret.txt')->path, $target));

        try {
            $report = $this->push(['public/target.txt' => 'new'], mirror: false);

            self::assertTrue($report->isComplete(), $report->render());
            self::assertStringContainsString(
                'public/target.txt is a symbolic link the push writes over',
                $report->render(),
            );
            self::assertSame(['added public/target.txt'], self::kinds($this->record()->read()));
            self::assertFalse(is_link($target), 'the push wrote through the link rather than over it');
            self::assertSame('untouchable', $outside->file('secret.txt')->read());

            self::assertTrue($this->applier()->rollback(true)->isComplete());
            self::assertFalse(file_exists($target), 'the rollback left what the push wrote');
            self::assertSame('untouchable', $outside->file('secret.txt')->read());
        } finally {
            if (is_link($target)) {
                unlink($target);
            }
        }
    }

    /**
     * A record reached through a link is never read, even when what is behind it is a real record.
     *
     * @return void
     */
    public function testARecordReachedThroughASymlinkIsNeverRead(): void
    {
        $this->plantTheFirstRelease();
        (void) $this->pushTheSecondRelease();

        $record = $this->sandbox . '/.update-previous';
        self::assertTrue(rename($record, $this->sandbox . '/moved'));
        self::assertTrue(symlink($this->sandbox . '/moved', $record));

        try {
            (void) $this->applier()->rollback(true);
            self::fail('a record was read through a symlink');
        } catch (UpdateException $refused) {
            self::assertStringContainsString('never read through one', $refused->getMessage());
        } finally {
            unlink($record);
        }

        $this->assertTheSecondReleaseIsLive();
    }

    /**
     * An index that is there and cannot be read is a refusal, not an absent record.
     *
     * @return void
     */
    public function testAnIndexThatCannotBeReadRefusesTheRollback(): void
    {
        $this->plantTheFirstRelease();
        (void) $this->pushTheSecondRelease();

        $index = new File($this->sandbox . '/.update-previous/release');
        self::assertTrue(chmod($index->path, 0o000));

        try {
            if ($index->read() !== null) {
                self::markTestSkipped('this process reads a file whatever its mode (running as root?)');
            }

            (void) $this->applier()->rollback(true);
            self::fail('an unreadable index was taken for no record');
        } catch (UpdateException $refused) {
            self::assertStringContainsString('is there and cannot be read', $refused->getMessage());
        } finally {
            chmod($index->path, 0o600);
        }

        $this->assertTheSecondReleaseIsLive();
    }

    /**
     * A live file that is no longer the bytes its entry was recorded under is not saved: the record
     * is abandoned rather than holding a copy its index does not describe.
     *
     * Reached through {@link ReleaseRecord::take()} with an entry built by hand, which is the one
     * way to put a digest and a live file out of step without racing a push.
     *
     * @return void
     */
    public function testALiveFileThatNoLongerMatchesItsEntryIsNotSaved(): void
    {
        self::assertTrue($this->web()->file('a.txt')->write('actual'));

        $refused = $this->record()->take(
            null,
            new Collection(RecordEntry::class)->with(
                RecordEntry::changed(UpdateRoot::Public, 'public/a.txt', 'what was recorded', 'new'),
            ),
            $this->deployment(),
        );

        self::assertSame("'public/a.txt' could not be read, or changed while it was being recorded", $refused);
        self::assertNull($this->record()->read(), 'the abandoned record was left behind');
        self::assertFalse(new File($this->sandbox . '/.update-previous/saved/public/a.txt')->exists());
        self::assertSame('actual', $this->web()->file('a.txt')->read());
    }

    /**
     * A `saved/` directory that is a link refuses the push, and nothing lands where it points.
     *
     * @return void
     */
    public function testASavedDirectoryThatIsASymlinkRefusesThePush(): void
    {
        $this->plantTheFirstRelease();
        $outside = $this->outside();
        $saved   = $this->sandbox . '/.update-previous/saved';
        self::assertTrue(new Directory($this->sandbox . '/.update-previous')->create());
        self::assertTrue(symlink($outside->path, $saved));

        try {
            (void) $this->pushTheSecondRelease();
            self::fail('a copy was saved through a symlinked saved/');
        } catch (UpdateException $refused) {
            self::assertStringContainsString('would be saved through a symbolic link', $refused->getMessage());
        } finally {
            unlink($saved);
        }

        $this->assertTheFirstReleaseIsLive();
        self::assertSame(['secret.txt'], self::namesIn($outside), 'a copy landed through the link');
    }

    /**
     * An old record whose copies cannot be removed is one the next push will not replace, so the
     * push is refused with nothing written.
     *
     * @return void
     */
    public function testAnOldRecordThatCannotBeClearedRefusesThePush(): void
    {
        $this->plantTheFirstRelease();
        (void) $this->pushTheSecondRelease();

        $saved = new Directory($this->sandbox . '/.update-previous/saved/public');
        self::assertTrue(chmod($saved->path, 0o555));

        if (is_writable($saved->path)) {
            chmod($saved->path, 0o700);
            self::markTestSkipped('this process can write to a read-only directory');
        }

        try {
            (void) $this->push(['public/other.bin' => 'newer', 'public/assets/fresh.js' => 'fresh']);
            self::fail('a push replaced a record it could not clear');
        } catch (UpdateException $refused) {
            self::assertStringContainsString(
                "the saved copy of 'public/other.bin' could not be removed",
                $refused->getMessage(),
            );
        } finally {
            chmod($saved->path, 0o700);
        }

        $this->assertTheSecondReleaseIsLive();
        self::assertTrue($this->record()->read()?->complete, 'the old record was damaged by the refusal');
    }

    /**
     * A restore that cannot be renamed into place — a live directory the process may not write
     * into, which staging cannot see coming — is named, and the rollback removes nothing, answers
     * 500 and keeps the record, so it can be run again.
     *
     * @return void
     */
    public function testARestoreThatCannotLandIsNamedAndKeepsTheRecord(): void
    {
        $this->plantTheFirstRelease();
        (void) $this->pushTheSecondRelease();

        $web = $this->web();
        self::assertTrue(chmod($web->path, 0o555));

        if (is_writable($web->path)) {
            chmod($web->path, 0o755);
            self::markTestSkipped('this process can write to a read-only directory');
        }

        try {
            $response = new UpdateRollback(
                ApplyManifest::parse('{"apply":true}', 'rollback'),
                $this->applier(),
            )->handle();
        } finally {
            chmod($web->path, 0o755);
        }

        $body = UpdateFixture::bodyOf($response);

        self::assertSame(HttpStatusCode::InternalServerError, UpdateFixture::statusOf($response));
        self::assertStringContainsString('! public/other.bin — could not be renamed into place', $body);
        self::assertStringContainsString('! public/old/gone.js — its directory could not be created', $body);
        self::assertStringContainsString('nothing the push added was removed', $body);
        self::assertStringContainsString('the record was kept', $body);
        self::assertSame('fresh', $web->file('assets/fresh.js')->read(), 'a failed restore removed a file');
        self::assertTrue($this->record()->read()?->complete);

        self::assertTrue($this->applier()->rollback(true)->isComplete());
        $this->assertTheFirstReleaseIsLive();
    }

    /**
     * A file the push added that cannot be removed is named, and the record is kept so the same
     * rollback can finish once it can.
     *
     * @return void
     */
    public function testAnAddedFileThatCannotBeRemovedKeepsTheRecord(): void
    {
        $this->plantTheFirstRelease();
        (void) $this->pushTheSecondRelease();

        // unlink() needs write permission on the directory, not on the file.
        $assets = $this->web()->directory('assets');
        self::assertTrue(chmod($assets->path, 0o555));

        if (is_writable($assets->path)) {
            chmod($assets->path, 0o755);
            self::markTestSkipped('this process can write to a read-only directory');
        }

        try {
            $report = $this->applier()->rollback(true);
        } finally {
            chmod($assets->path, 0o755);
        }

        self::assertFalse($report->isComplete());
        self::assertStringContainsString('! public/assets/fresh.js — could not be removed', $report->render());
        self::assertStringContainsString('the record was kept', $report->render());
        self::assertTrue($this->record()->read()?->complete, 'an incomplete rollback cleared the record');

        self::assertTrue($this->applier()->rollback(true)->isComplete());
        $this->assertTheFirstReleaseIsLive();
    }

    /**
     * The single-file root rolls back like any other path: an autoloader the push added is removed,
     * and there is no tree of its own to sweep.
     *
     * @return void
     */
    public function testAnAddedAutoloaderIsRemoved(): void
    {
        $autoload = new File($this->sandbox . '/autoload.php');
        self::assertFalse($autoload->exists());

        (void) $this->push(['autoload.php' => '<?php // new', 'public/a.txt' => 'a'], mirror: false);
        self::assertTrue($autoload->exists());

        $report = $this->applier()->rollback(true);

        self::assertTrue($report->isComplete(), $report->render());
        self::assertStringContainsString('- autoload.php', $report->render());
        self::assertFalse($autoload->exists());
        self::assertFalse($this->web()->file('a.txt')->exists());
        self::assertTrue($this->web()->exists(), 'the sweep removed the webroot itself');
    }

    // ───────────────────────────── the index ─────────────────────────────

    /**
     * What a rollback does with a path, for every kind and every state it can find the path in.
     *
     * @return void
     */
    public function testEachKindStepsFromThePushedStateOnly(): void
    {
        $old   = RecordEntry::digest('old');
        $new   = RecordEntry::digest('new');
        $other = RecordEntry::digest('other');

        $changed = RecordEntry::changed(UpdateRoot::Public, 'public/a', 'old', 'new');
        $added   = RecordEntry::added(UpdateRoot::Public, 'public/a', 'new');
        $deleted = RecordEntry::deleted(UpdateRoot::Public, 'public/a', 'old');

        self::assertSame(RollbackStep::Restore, $changed->step($new));
        self::assertSame(RollbackStep::Keep, $changed->step($old));
        self::assertSame(RollbackStep::Conflict, $changed->step(null));
        self::assertSame(RollbackStep::Conflict, $changed->step($other));
        self::assertSame(RollbackStep::Conflict, $changed->step(''));

        self::assertSame(RollbackStep::Remove, $added->step($new));
        self::assertSame(RollbackStep::Keep, $added->step(null));
        self::assertSame(RollbackStep::Conflict, $added->step($other));

        self::assertSame(RollbackStep::Restore, $deleted->step(null));
        self::assertSame(RollbackStep::Keep, $deleted->step($old));
        self::assertSame(RollbackStep::Conflict, $deleted->step($new));

        self::assertTrue(RecordKind::Changed->saves());
        self::assertTrue(RecordKind::Deleted->saves());
        self::assertFalse(RecordKind::Added->saves());
    }

    /**
     * An index reads back as exactly what was written.
     *
     * @return void
     */
    public function testAnIndexRoundTrips(): void
    {
        $release = new PreviousRelease(
            1757000000,
            true,
            new Collection(RecordEntry::class)->with(
                RecordEntry::changed(UpdateRoot::Framework, 'phpanta/src/App.php', 'a', 'b'),
                RecordEntry::added(UpdateRoot::Autoload, 'autoload.php', 'c'),
                RecordEntry::deleted(UpdateRoot::Public, 'public/.htaccess', 'd'),
            ),
        );

        $read = PreviousRelease::parse($release->render());

        self::assertSame($release->render(), $read->render());
        self::assertSame(UpdateRoot::Autoload, $read->entries->toValues()[1]->root);
        $bare = new PreviousRelease(null, false, new Collection(RecordEntry::class));

        self::assertNull(PreviousRelease::parse($bare->render())->serial);
    }

    /**
     * A line is refused unless it is one this class writes, naming a path a push could have written.
     *
     * @param string $line
     * @param string $expected
     * @return void
     */
    #[DataProvider('refusedLineProvider')]
    public function testALineThePushCouldNotHaveWrittenIsRefused(string $line, string $expected): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage($expected);

        RecordEntry::parse($line);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedLineProvider(): iterable
    {
        $h = self::hex();

        yield 'an unknown kind'          => ["moved - $h public/a", 'kind and states do not agree'];
        yield 'an add with a before'     => ["added $h $h public/a", 'kind and states do not agree'];
        yield 'a delete with an after'   => ["deleted $h $h public/a", 'kind and states do not agree'];
        yield 'a change with no before'  => ["changed - $h public/a", 'kind and states do not agree'];
        yield 'a short digest'           => ['added - abc public/a', 'cannot read'];
        yield 'a second space'           => ["added -  $h public/a", 'cannot read'];
        yield 'a traversal'              => ["added - $h public/../data/admin.php", 'walks the tree'];
        yield 'a data/ path'             => ["added - $h data/admin.php", 'under none of the roots'];
        yield 'an absolute path'         => ["added - $h /etc/passwd", 'not a plain relative path'];
        yield 'a tree root as a file'    => ["added - $h public", 'a tree this push writes into'];
        yield 'a name over the bound'    => ["added - $h public/" . str_repeat('a', 256), 'empty or over 255 bytes'];
    }

    /**
     * An index is refused unless it opens the way this class writes one, and names each path once.
     *
     * @param string $text
     * @param string $expected
     * @return void
     */
    #[DataProvider('refusedIndexProvider')]
    public function testAnIndexThisClassDidNotWriteIsRefused(string $text, string $expected): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage($expected);

        PreviousRelease::parse($text);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedIndexProvider(): iterable
    {
        $head = "phpanta previous-release 1\nserial 1\nstate complete\n";
        $line = 'added - ' . self::hex() . " public/a\n";

        yield 'cut short'       => [rtrim($head), 'does not open the way'];
        yield 'another format'  => [str_replace('release 1', 'release 2', $head), 'does not open the way'];
        yield 'serial zero'     => [str_replace('serial 1', 'serial 0', $head), 'does not open the way'];
        yield 'an unknown state' => [str_replace('complete', 'done', $head), 'does not open the way'];
        yield 'a name twice'    => [$head . $line . $line, "names 'public/a' twice"];
    }

    // ───────────────────────────── the action ─────────────────────────────

    /**
     * The action is a POST whose handler is built from the signed manifest, and writes exactly when
     * it applies.
     *
     * @return void
     */
    public function testTheRollbackActionIsAWriteBuiltFromTheSignedManifest(): void
    {
        self::assertSame(HttpMethod::Post, UpdateAction::Rollback->method());

        foreach ([true, false] as $apply) {
            $handler = UpdateAction::Rollback->handler(self::verified(['apply' => $apply]));

            self::assertInstanceOf(UpdateRollback::class, $handler);
            self::assertSame($apply, $handler->isWrite());
        }

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('must carry apply:bool');

        (void) UpdateAction::Rollback->handler(self::verified([]));
    }

    /**
     * The manifest's one field is required and typed, and anything else in it is not its business.
     *
     * @param string $json
     * @return void
     */
    #[DataProvider('badRollbackManifestProvider')]
    public function testAMalformedRollbackManifestIsRefused(string $json): void
    {
        $this->expectException(UpdateException::class);

        ApplyManifest::parse($json, 'rollback');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badRollbackManifestProvider(): iterable
    {
        yield 'not JSON'      => ['{'];
        yield 'not an object' => ['"a string"'];
        yield 'no apply'      => ['{"mirror":true}'];
        yield 'apply as int'  => ['{"apply":1}'];
    }

    /**
     * A rollback through its handler is a 200 carrying the report, and a dry run is too.
     *
     * @return void
     */
    public function testTheHandlerAnswersWithTheReport(): void
    {
        $this->plantTheFirstRelease();
        (void) $this->pushTheSecondRelease();

        $dry = new UpdateRollback(
            ApplyManifest::parse('{"apply":false,"mirror":true}', 'rollback'),
            $this->applier(),
        )->handle();
        self::assertSame(HttpStatusCode::Ok, UpdateFixture::statusOf($dry));
        self::assertStringContainsString('dry run', UpdateFixture::bodyOf($dry));

        $real = new UpdateRollback(ApplyManifest::parse('{"apply":true}', 'rollback'), $this->applier())->handle();
        self::assertSame(HttpStatusCode::Ok, UpdateFixture::statusOf($real));
        self::assertStringContainsString('- public/assets/fresh.js', UpdateFixture::bodyOf($real));
        $this->assertTheFirstReleaseIsLive();
    }

    // ───────────────────────────── fixtures ─────────────────────────────

    /**
     * The release on disk before the push: one file the push changes, one it leaves alone, one the
     * mirror deletes from a directory it then sweeps.
     *
     * @return void
     */
    private function plantTheFirstRelease(): void
    {
        self::assertTrue($this->web()->file('other.bin')->write(self::OLD_BYTES));
        self::assertTrue($this->web()->file('same.txt')->write('identical'));
        self::assertTrue($this->web()->directory('old')->create());
        self::assertTrue($this->web()->file('old/gone.js')->write('gone'));
    }

    /**
     * The push: other.bin changed, same.txt carried unchanged, assets/fresh.js added, old/gone.js omitted.
     *
     * @param int|null $serial
     * @return UpdateReport
     */
    private function pushTheSecondRelease(?int $serial = null): UpdateReport
    {
        return $this->push([
            'public/other.bin'       => 'new',
            'public/same.txt'        => 'identical',
            'public/assets/fresh.js' => 'fresh',
        ], serial: $serial);
    }

    /**
     * @return void
     */
    private function assertTheFirstReleaseIsLive(): void
    {
        self::assertSame(self::OLD_BYTES, $this->web()->file('other.bin')->read(), 'other.bin is not the old bytes');
        self::assertSame('identical', $this->web()->file('same.txt')->read());
        self::assertSame('gone', $this->web()->file('old/gone.js')->read(), 'the deleted file is not back');
        self::assertFalse($this->web()->file('assets/fresh.js')->exists(), 'the added file is still there');
    }

    /**
     * @return void
     */
    private function assertTheSecondReleaseIsLive(): void
    {
        self::assertSame('new', $this->web()->file('other.bin')->read());
        self::assertSame('fresh', $this->web()->file('assets/fresh.js')->read());
        self::assertFalse($this->web()->file('old/gone.js')->exists());
    }

    /**
     * Backdates same.txt, so a rewrite would show in its mtime, and answers the stamp.
     *
     * @return int
     */
    private function ageSame(): int
    {
        $path  = $this->web()->file('same.txt')->path;
        $stamp = (int) filemtime($path) - 60;
        self::assertTrue(touch($path, $stamp));
        clearstatcache();

        return $stamp;
    }

    /**
     * @param array<string, string> $files
     * @param bool $mirror
     * @param int|null $serial
     * @return UpdateReport
     */
    private function push(array $files, bool $mirror = true, ?int $serial = null): UpdateReport
    {
        return $this->applier()->apply(UpdateFixture::archive($files), self::manifest(mirror: $mirror), $serial);
    }

    /**
     * An applier that can reach nothing but the sandbox — its record included.
     *
     * @return UpdateApplier
     */
    private function applier(): UpdateApplier
    {
        return new UpdateApplier(new Deployment(new Directory($this->sandbox), $this->web()));
    }

    /**
     * @return ReleaseRecord
     */
    private function record(): ReleaseRecord
    {
        return new ReleaseRecord($this->deployment()->previousRelease());
    }

    /**
     * The sandbox, as a deployment.
     *
     * @return Deployment
     */
    private function deployment(): Deployment
    {
        return new Deployment(new Directory($this->sandbox), $this->web());
    }

    /**
     * @return Directory
     */
    private function web(): Directory
    {
        return new Directory($this->sandbox . '/public');
    }

    /**
     * A directory outside every root and outside the record, holding one file nothing may reach.
     *
     * @return Directory
     */
    private function outside(): Directory
    {
        $outside = new Directory($this->sandbox . '/outside');

        if (!$outside->exists()) {
            self::assertTrue($outside->create());
            self::assertTrue($outside->file('secret.txt')->write('untouchable'));
        }

        return $outside;
    }

    /**
     * A digest-shaped run of hex, for lines that must be refused for something else.
     *
     * @return string
     */
    private static function hex(): string
    {
        return str_repeat('a', 96);
    }

    /**
     * Each entry of $release as `kind name`, in the order the index holds them.
     *
     * @param PreviousRelease|null $release
     * @return list<string>
     */
    private static function kinds(?PreviousRelease $release): array
    {
        return $release?->entries->map(
            static fn(RecordEntry $entry): string => $entry->kind->value . ' ' . $entry->name,
        )->toValues() ?? [];
    }

    /**
     * @param Directory $directory
     * @return list<string>
     */
    private static function namesIn(Directory $directory): array
    {
        return array_values(array_diff((array) scandir($directory->path), ['.', '..']));
    }

    /**
     * @param bool $apply
     * @param bool $mirror
     * @return UpdateManifest
     */
    private static function manifest(bool $apply = true, bool $mirror = true): UpdateManifest
    {
        return UpdateManifest::parse(json_encode(['apply' => $apply, 'mirror' => $mirror], JSON_THROW_ON_ERROR));
    }

    /**
     * A verified rollback carrying $fields, as the gate would hand it over.
     *
     * @param array<string, bool> $fields
     * @return VerifiedRequest
     */
    private static function verified(array $fields): VerifiedRequest
    {
        $manifest = json_encode([
            'serial' => time(),
            'method' => 'POST',
            'path'   => '/api/update/v1/rollback',
            'digest' => hash('sha256', ''),
            'size'   => 0,
            ...$fields,
        ], JSON_THROW_ON_ERROR);

        return new VerifiedRequest(ApiEnvelope::parse($manifest), $manifest, '');
    }
}
