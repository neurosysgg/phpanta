<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Exception\ApiException;
use Phpanta\Http\Api\ActionField;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\Api\MachineAction;
use Phpanta\Http\Api\ResultFile;
use Phpanta\Http\ContentDisposition;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Upload;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Machine\CommandOutcome;
use Phpanta\Model\Machine\DirectorySection;
use Phpanta\Model\Machine\EntryKind;
use Phpanta\Model\Machine\FileSection;
use Phpanta\Model\Machine\LinkSection;
use Phpanta\Model\Machine\MachineArea;
use Phpanta\Model\Machine\MachineConfig;
use Phpanta\Model\Machine\MachineCounter;
use Phpanta\Model\Machine\MachineCounters;
use Phpanta\Model\Machine\MachineEntry;
use Phpanta\Model\Machine\MachineManifest;
use Phpanta\Model\Machine\MachinePath;
use Phpanta\Model\Machine\MachineProcess;
use Phpanta\Model\Machine\MachineReading;
use Phpanta\Model\Machine\MachineRefusal;
use Phpanta\Model\Machine\Measure;
use Phpanta\Model\Machine\MediaExtension;
use Phpanta\Model\Machine\MediaKind;
use Phpanta\Model\Machine\ProcessSection;
use Phpanta\Model\Machine\StatsSection;
use Phpanta\Model\Machine\TextSection;
use Phpanta\Model\Machine\Whereabouts;
use Phpanta\Service\Api\MachineBytes;
use Phpanta\Service\Api\MachineDelete;
use Phpanta\Service\Api\MachineFiles;
use Phpanta\Service\Api\MachineFolder;
use Phpanta\Service\Api\MachineProcesses;
use Phpanta\Service\Api\MachineRename;
use Phpanta\Service\Api\MachineRun;
use Phpanta\Service\Api\MachineSystem;
use Phpanta\Service\Api\MachineUpload;
use Phpanta\Service\Machine\CommandRunner;
use Phpanta\Service\Machine\MachineProbe;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Test\TestRequest;
use Phpanta\Text\AdminText;
use Phpanta\Text\Language;
use Phpanta\View\Html\MachineAttribute;
use Phpanta\View\Html\MachineTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The admin's `machine` service, from what `data/machine.json` switches on to what each action
 * answers: a place resolved under a root or refused, a directory listed, a file shown by its kind,
 * and every write — dry and applied — against a sandbox with a link that leads out of its root.
 *
 * How the service reaches a caller — the gate, the listings, the browser's tap — is
 * {@link MachineAdminTest}'s; what a machine says about itself is {@link MachineProbeTest}'s.
 */
#[CoversClass(MachineConfig::class)]
#[CoversClass(MachinePath::class)]
#[CoversClass(MachineEntry::class)]
#[CoversClass(EntryKind::class)]
#[CoversClass(Measure::class)]
#[CoversClass(MediaKind::class)]
#[CoversClass(MediaExtension::class)]
#[CoversClass(MachineManifest::class)]
#[CoversClass(MachineCounters::class)]
#[CoversClass(MachineProcess::class)]
#[CoversClass(MachineRefusal::class)]
#[CoversClass(DirectorySection::class)]
#[CoversClass(FileSection::class)]
#[CoversClass(StatsSection::class)]
#[CoversClass(ProcessSection::class)]
#[CoversClass(TextSection::class)]
#[CoversClass(LinkSection::class)]
#[CoversClass(Whereabouts::class)]
#[CoversClass(CommandOutcome::class)]
#[CoversClass(MachineAction::class)]
#[CoversClass(MachineFiles::class)]
#[CoversClass(MachineBytes::class)]
#[CoversClass(MachineUpload::class)]
#[CoversClass(MachineFolder::class)]
#[CoversClass(MachineRename::class)]
#[CoversClass(MachineDelete::class)]
#[CoversClass(MachineRun::class)]
#[CoversClass(MachineSystem::class)]
#[CoversClass(MachineProcesses::class)]
#[CoversClass(ResultFile::class)]
#[CoversClass(ApiResult::class)]
#[CoversClass(MachineTag::class)]
#[CoversClass(MachineAttribute::class)]
final class MachineTest extends TestCase
{
    private string $sandbox = '';

    /** The root the service may walk: `root/` in the sandbox, resolved. */
    private string $root = '';

    /**
     * A root holding a directory of files of every kind, an empty directory, a link that stays inside
     * and one that leads out — to a file beside the root, which nothing may reach.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->sandbox = (string) realpath(Directory::temporary('phpanta-machine-')->path);
        $this->root    = $this->sandbox . '/root';

        foreach (['/root/docs', '/root/empty', '/outside'] as $directory) {
            self::assertTrue(new Directory($this->sandbox . $directory)->create());
        }

        $files = [
            '/root/docs/readme.txt' => "hello, machine\n",
            '/root/docs/photo.png'  => "\x89PNG\r\n\x1a\n",
            '/root/docs/song.flac'  => 'fLaC' . str_repeat("\1", 64),
            '/root/docs/page.html'  => '<script>alert(1)</script>',
            '/root/docs/blob'       => "\0\1\2\3",
            '/root/docs/Notes'      => "plain words\n",
            '/root/docs/film.webm'  => 'webm',
            '/root/docs/book.pdf'   => '%PDF-1.7',
            '/outside/secret.txt'   => 'no',
        ];

        foreach ($files as $path => $contents) {
            self::assertTrue(new File($this->sandbox . $path)->write($contents));
        }

        self::assertTrue(symlink($this->sandbox . '/outside', $this->root . '/out'));
        self::assertTrue(symlink($this->root . '/docs', $this->root . '/in'));
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

    // ───────────────────────── the switch ─────────────────────────

    /**
     * Only a file that reads switches the service on: roots that are directories here, writes and
     * commands as bools, commands only with writes, and an origin that is one.
     *
     * @return void
     */
    public function testTheSwitchFileDecidesWhatTheServiceReaches(): void
    {
        $on = MachineConfig::parse((string) json_encode([
            'roots'    => [$this->root, $this->sandbox . '/nowhere', $this->root . '/docs/../docs'],
            'writes'   => true,
            'commands' => true,
            'origin'   => 'https://machine.example',
        ]));

        self::assertNotNull($on);
        self::assertSame(
            [$this->root, $this->root . '/docs'],
            $on->roots()->map(static fn(Directory $root): string => $root->path)->toValues(),
        );
        self::assertTrue($on->writes);
        self::assertTrue($on->commands);
        self::assertSame('https://machine.example', $on->origin?->render());

        $closed = MachineConfig::parse((string) json_encode(['roots' => [$this->root], 'commands' => true]));

        self::assertNotNull($closed);
        self::assertFalse($closed->writes);
        self::assertFalse($closed->commands, 'a command is a write, so no writes is no commands');
        self::assertNull($closed->origin);

        $cases = [
            'not JSON'            => '{',
            'not an object'       => '[]',
            'no roots'            => '{}',
            'roots not a list'    => '{"roots": "/"}',
            'a root not a string' => '{"roots": [1]}',
            'no root is here'     => (string) json_encode(['roots' => [$this->sandbox . '/nowhere']]),
            'writes not a bool'   => (string) json_encode(['roots' => [$this->root], 'writes' => 'yes']),
            'origin not a string' => (string) json_encode(['roots' => [$this->root], 'origin' => 1]),
            'origin not one'      => (string) json_encode(['roots' => [$this->root], 'origin' => 'ftp://x/y']),
        ];

        foreach ($cases as $case => $json) {
            self::assertNull(MachineConfig::parse($json), $case);
        }

        self::assertNull(MachineConfig::current(new File($this->sandbox . '/machine.json')), 'absent is off');
        $written = new File($this->sandbox . '/machine.json')->write((string) json_encode(['roots' => [$this->root]]));
        self::assertTrue($written);
        self::assertNotNull(MachineConfig::current(new File($this->sandbox . '/machine.json')));
    }

    // ───────────────────────── places ─────────────────────────

    /**
     * A subject resolves to a place under a root, every link followed — and to nothing where it
     * spells a dot segment, is not there, or lands outside every root.
     *
     * @return void
     */
    public function testAPathIsResolvedUnderARootOrNotAtAll(): void
    {
        $config = $this->config();
        $docs   = MachinePath::resolve($config, $this->subject('/docs'));

        self::assertSame($this->root . '/docs', $docs?->path);
        self::assertSame($this->root . '/docs', MachinePath::resolve($config, $this->subject('/in'))?->path);
        self::assertSame($this->root, MachinePath::resolve($config, null)?->path, 'no subject is the one root');

        $cases = [
            'outside the root'   => $this->subject('/out/secret.txt'),
            'above the root'     => ltrim($this->sandbox . '/outside/secret.txt', '/'),
            'a dot segment'      => $this->subject('/docs/../docs'),
            'a current segment'  => $this->subject('/docs/./readme.txt'),
            'an empty segment'   => $this->subject('/docs//readme.txt'),
            'a NUL'              => $this->subject("/docs/read\0me.txt"),
            'not there'          => $this->subject('/nothing'),
        ];

        foreach ($cases as $case => $subject) {
            self::assertNull(MachinePath::resolve($config, $subject), $case);
        }

        $two = $this->config(roots: [$this->root, $this->sandbox . '/outside']);

        self::assertNull(MachinePath::resolve($two, null), 'several roots are the caller\'s to list');
        self::assertSame(
            $this->sandbox . '/outside/secret.txt',
            MachinePath::resolve($two, $this->subject('/out/secret.txt'))?->path,
            'a link that lands under another root lands somewhere reachable',
        );

        $everywhere = $this->config(roots: ['/']);

        self::assertSame('/', MachinePath::resolve($everywhere, null)?->path);
        self::assertSame($this->root . '/docs', MachinePath::resolve($everywhere, $this->subject('/docs'))?->path);
    }

    /**
     * An entry is named, not followed: a link is the link, a root is no entry, and what is not there is
     * no entry either — and a place knows its parent, its trail from the root, and its children.
     *
     * @return void
     */
    public function testAnEntryIsTheLinkNotWhereItLeads(): void
    {
        $config = $this->config();
        $out    = MachinePath::entry($config, $this->subject('/out'));

        self::assertSame($this->root . '/out', $out?->path);
        self::assertSame('out', $out?->name());
        self::assertNull(MachinePath::entry($config, (string) $this->subject('')), 'a root is not an entry');
        self::assertNull(MachinePath::entry($config, $this->subject('/nothing')));
        self::assertNull(MachinePath::entry($config, $this->subject('/nothing/else')), 'its directory is not there');
        self::assertNull(MachinePath::entry($config, $this->subject('/docs/../out')));
        self::assertNull(MachinePath::entry($config, $this->subject('/out/secret.txt')), 'its directory is outside');

        $readme = MachinePath::resolve($config, $this->subject('/docs/readme.txt'));

        self::assertNotNull($readme);
        self::assertFalse($readme->isRoot());
        self::assertFalse($readme->isDirectory());
        self::assertSame($this->root . '/docs', $readme->parent()?->path);
        self::assertSame(
            [$this->root, $this->root . '/docs', $this->root . '/docs/readme.txt'],
            $readme->trail()->map(static fn(MachinePath $step): string => $step->path)->toValues(),
        );
        self::assertSame($this->root . '/docs/readme.txt', $readme->file()->path);

        $root = MachinePath::resolve($config, null);

        self::assertNotNull($root);
        self::assertTrue($root->isRoot());
        self::assertNull($root->parent());
        self::assertSame($this->root . '/x', $root->child('x'));
        self::assertSame($this->root, $root->directory()->path);

        // At the entry just under the filesystem's root, the directory it is in is `/`.
        self::assertSame('/tmp', MachinePath::entry($this->config(roots: ['/']), 'tmp')?->path);
        self::assertSame('/', MachinePath::rooted(new Directory('/'))->name(), 'the root is named by its path');
        self::assertSame('/x', MachinePath::rooted(new Directory('/'))->child('x'));
    }

    /**
     * A name is one segment, short enough, with nothing that would make it more than one; a path is
     * written with forward slashes, and addressed without its leading one.
     *
     * @return void
     */
    public function testANameIsOneSegment(): void
    {
        foreach (['a', 'a b.flac', '.hidden', 'ü', str_repeat('x', 255)] as $name) {
            self::assertTrue(MachinePath::isName($name), $name);
        }

        foreach (['', '.', '..', 'a/b', 'a\\b', "a\0b", str_repeat('x', 256)] as $name) {
            self::assertFalse(MachinePath::isName($name), $name);
        }

        self::assertSame('C:/Users', MachinePath::normalised('C:\\Users\\'));
        self::assertSame('/', MachinePath::normalised('/'));
        self::assertSame('etc/hosts', MachinePath::subjectOf('/etc/hosts'));
        self::assertNull(MachinePath::subjectOf('/'));
        self::assertFalse(MachinePath::onWindows());
    }

    // ───────────────────────── entries and kinds ─────────────────────────

    /**
     * A listing is every entry, directories first and then by name as a person reads it, each with
     * what `ls -l` would say of it — and a link says where it leads.
     *
     * @return void
     */
    public function testADirectoryListsDirectoriesFirst(): void
    {
        $names = MachineEntry::in($this->root)->map(static fn(MachineEntry $entry): string => $entry->name)->toValues();

        self::assertSame(['docs', 'empty', 'in', 'out'], $names);
        self::assertSame(
            ['blob', 'book.pdf', 'film.webm', 'Notes', 'page.html', 'photo.png', 'readme.txt', 'song.flac'],
            MachineEntry::in($this->root . '/docs')
                ->map(static fn(MachineEntry $entry): string => $entry->name)
                ->toValues(),
        );
        self::assertTrue(MachineEntry::in($this->sandbox . '/nowhere')->isEmpty());
        self::assertNull(MachineEntry::at($this->sandbox . '/nowhere'));

        chmod($this->root . '/docs/readme.txt', 0o640);

        $readme = MachineEntry::at($this->root . '/docs/readme.txt');
        $link   = MachineEntry::at($this->root . '/out');
        $docs   = MachineEntry::at($this->root . '/docs');

        self::assertNotNull($readme);
        self::assertNotNull($link);
        self::assertNotNull($docs);
        self::assertSame(EntryKind::File, $readme->kind);
        self::assertSame('-rw-r-----', $readme->permissions());
        self::assertSame(15, $readme->size);
        self::assertSame(EntryKind::Link, $link->kind);
        self::assertSame($this->sandbox . '/outside', $link->target);
        self::assertTrue($link->opens, 'a link to a directory opens');
        self::assertStringEndsWith(' out -> ' . $this->sandbox . '/outside', $link->line());
        self::assertStringStartsWith('d', $docs->permissions());
        self::assertStringContainsString('          -  ', $docs->line(), 'a directory has no size to say');
        self::assertSame('/', MachineEntry::at('/')?->name);
        self::assertSame('elsewhere', $readme->named('elsewhere')->name);
        self::assertSame(
            ['name', 'kind', 'size', 'modified', 'mode', 'owner', 'target'],
            array_keys((array) $readme->jsonSerialize()),
        );
        self::assertSame('999999', MachineEntry::owner(999_999), 'a user the machine does not know is a number');

        self::assertSame(EntryKind::Other, EntryKind::ofMode(0o010644));
        self::assertSame('?', EntryKind::Other->mark());
    }

    /**
     * A file is what its extension says, or text where its first bytes are, or bytes to save — and
     * goes out typed and disposed by that: a page or a vector image is never shown as one.
     *
     * @return void
     */
    public function testWhatAFileIsToABrowser(): void
    {
        $kinds = [
            'readme.txt' => [MediaKind::Text, 'text/plain', true],
            'photo.png'  => [MediaKind::Image, 'image/png', true],
            'song.flac'  => [MediaKind::Audio, 'audio/flac', true],
            'film.webm'  => [MediaKind::Video, 'video/webm', true],
            'book.pdf'   => [MediaKind::Pdf, 'application/pdf', true],
            'page.html'  => [MediaKind::Text, 'text/plain', true],
            'Notes'      => [MediaKind::Text, 'text/plain', true],
            'blob'       => [MediaKind::Other, 'application/octet-stream', false],
        ];

        foreach ($kinds as $name => [$kind, $type, $shown]) {
            $file = new File($this->root . '/docs/' . $name);

            self::assertSame($kind, MediaKind::of($file), $name);
            self::assertSame($type, $kind->type($file)->essence(), $name);
            self::assertSame($shown, $kind->isShown(), $name);
            self::assertSame(
                $shown ? 'inline' : 'attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . $name,
                $kind->disposition($file)->render(),
                $name,
            );
        }

        self::assertSame(MediaKind::Other, MediaKind::of(new File($this->root . '/docs/unread')), 'nothing to read');
        self::assertTrue(new File($this->root . '/docs/long')->write(str_repeat('é', 4000)));
        self::assertSame(
            MediaKind::Text,
            MediaKind::of(new File($this->root . '/docs/long')),
            'a cut character is no reason to doubt',
        );

        foreach (MediaExtension::cases() as $extension) {
            self::assertNotSame('', $extension->subtype(), $extension->value);
        }

        self::assertSame('vnd.microsoft.icon', MediaExtension::Ico->subtype());
        self::assertSame(MediaKind::Text, MediaExtension::Svg->kind(), 'a vector image may carry script');
    }

    /**
     * Quantities are written in one set of words.
     *
     * @return void
     */
    public function testQuantitiesAreWrittenOneWay(): void
    {
        self::assertSame('512 B', Measure::bytes(512));
        self::assertSame('1.5 KiB', Measure::bytes(1536));
        self::assertSame('931.5 GiB', Measure::bytes(1_000_204_886_016));
        self::assertSame('8.0 EiB', Measure::bytes(PHP_INT_MAX));
        self::assertSame('3 d 4 h 12 min', Measure::duration(3 * 86_400 + 4 * 3_600 + 12 * 60 + 5));
        self::assertSame('2 h 0 min', Measure::duration(7_200));
        self::assertSame('0 min', Measure::duration(59));
        self::assertSame('50%', Measure::percent(1, 2));
        self::assertSame('0%', Measure::percent(1, 0));
        self::assertSame('512 B of 1.0 KiB (50%)', Measure::share(512, 1024));
        self::assertSame(date('Y-m-d H:i', 0), Measure::moment(0));
    }

    /**
     * A write's manifest carries `apply` as a bool, and the name or command line its action takes —
     * held to what either may be.
     *
     * @return void
     */
    public function testAManifestCarriesWhatItsActionTakes(): void
    {
        $folder = MachineManifest::parse('{"apply":true,"target":"new"}', MachineAction::Folder);
        $run    = MachineManifest::parse('{"apply":false,"command":"ls -la"}', MachineAction::Run);
        $delete = MachineManifest::parse('{"apply":true,"target":"../x"}', MachineAction::Delete);

        self::assertTrue($folder->apply);
        self::assertSame('new', $folder->target);
        self::assertFalse($run->apply);
        self::assertSame('ls -la', $run->command);
        self::assertSame('../x', $delete->target, 'a field the action does not take is not asked about');

        $cases = [
            [MachineAction::Folder, '{'],
            [MachineAction::Folder, '[]'],
            [MachineAction::Folder, '{"target":"new"}'],
            [MachineAction::Folder, '{"apply":true}'],
            [MachineAction::Rename, '{"apply":true,"target":"a/b"}'],
            [MachineAction::Rename, '{"apply":true,"target":7}'],
            [MachineAction::Run, '{"apply":true}'],
            [MachineAction::Run, '{"apply":true,"command":"   "}'],
            [MachineAction::Run, (string) json_encode(['apply' => true, 'command' => str_repeat('x', 8193)])],
            [MachineAction::Run, (string) json_encode(['apply' => true, 'command' => "a\0b"])],
        ];

        foreach ($cases as [$action, $json]) {
            try {
                (void) MachineManifest::parse($json, $action);
                self::fail('parsed ' . $json);
            } catch (ApiException $refused) {
                self::assertStringStartsWith('the ' . $action->value . ' manifest', $refused->getMessage());
            }
        }
    }

    /**
     * Each counter is under its own key, and a process is written as a line and as data.
     *
     * @return void
     */
    public function testCountersAndProcessesSayWhatTheyAre(): void
    {
        $counters = new MachineCounters(1, 2, 3, 4, 5, 6, 7, 8, 9, 10);
        $values   = [];

        foreach (MachineCounter::cases() as $counter) {
            $values[$counter->value] = $counters->of($counter);
        }

        self::assertSame(range(1, 10), array_values($values));
        self::assertSame($values, $counters->jsonSerialize());

        $process = new MachineProcess(42, 'root', 'S', 2048, 90, 0, '/sbin/init');

        self::assertStringContainsString('42  root       S    2.0 KiB      1 min', $process->line());
        self::assertSame(
            ['pid', 'owner', 'state', 'memory', 'cpu', 'started', 'command'],
            array_keys((array) $process->jsonSerialize()),
        );
    }

    // ───────────────────────── sections ─────────────────────────

    /**
     * A directory's page is where it is, what may be done there, a filter, and a table of links —
     * each entry opening, each file savable, and the way up; its text is `ls -l`'s, and its data
     * says where each entry opens.
     *
     * @return void
     */
    public function testADirectoryIsATableOfLinks(): void
    {
        $config  = $this->config(commands: true);
        $docs    = MachinePath::resolve($config, $this->subject('/docs'));
        self::assertNotNull($docs);
        $section = DirectorySection::of($docs, $config);
        $html    = $section->node()->render(0, Language::English);
        $subject = (string) $this->subject('/docs');

        self::assertStringContainsString('<nav aria-label="Where this is">', $html);
        self::assertStringContainsString('href="/admin/machine/v1/upload/' . $subject . '"', $html);
        self::assertStringContainsString('href="/admin/machine/v1/folder/' . $subject . '"', $html);
        self::assertStringContainsString('href="/admin/machine/v1/run/' . $subject . '"', $html);
        self::assertStringContainsString('<machine-filter hidden>', $html);
        self::assertStringContainsString('<tr data-entry="notes">', $html);
        self::assertStringContainsString(
            'href="/admin/machine/v1/download/' . $subject . '/song.flac" download>',
            $html,
        );
        self::assertStringContainsString('<a href="/admin/machine/v1/files/' . $this->subject('') . '">..</a>', $html);
        self::assertStringNotContainsString(AdminText::MoreEntries->in(Language::English), $html);

        $text = $section->render();

        self::assertStringStartsWith($this->root . "/docs\n", $text);
        self::assertStringContainsString(' readme.txt', $text);

        $data = (array) json_decode((string) json_encode($section), true);

        self::assertSame(8, $data['held']);
        self::assertSame('/admin/machine/v1/files/' . $subject . '/blob', $data['entries'][0]['href']);

        $readOnly = DirectorySection::of($docs, $this->config(writes: false))->node()->render(0, Language::English);

        self::assertStringNotContainsString('/upload/', $readOnly);
        self::assertStringNotContainsString('/run/', $readOnly);
    }

    /**
     * A listing shows its first entries and says there are more; the roots, where there are several,
     * are listed by their paths.
     *
     * @return void
     */
    public function testAListingIsBoundedAndTheRootsAreListed(): void
    {
        $crowd = new Directory($this->root . '/crowd');
        self::assertTrue($crowd->create());

        for ($index = 0; $index <= DirectorySection::MOST; $index++) {
            touch($crowd->path . '/f' . $index);
        }

        $config  = $this->config();
        $place   = MachinePath::resolve($config, $this->subject('/crowd'));
        self::assertNotNull($place);
        $section = DirectorySection::of($place, $config);

        self::assertStringContainsString(
            AdminText::MoreEntries->in(Language::English),
            $section->node()->render(0, Language::English),
        );
        self::assertSame(DirectorySection::MOST + 1, ((array) $section->jsonSerialize())['held']);

        $roots = DirectorySection::roots($this->config(roots: [$this->root, $this->sandbox . '/outside']));

        self::assertStringStartsWith(MachineArea::Roots->value . "\n", $roots->render());
        self::assertStringContainsString(' ' . $this->sandbox . '/outside', $roots->render());
        self::assertStringNotContainsString('<nav', $roots->node()->render(0, Language::English));
    }

    /**
     * A file's page says what it is and shows it by kind: a picture, a recording, a film, text written
     * as text, a link for a PDF, and nothing of anything else — and text is cut, and says so.
     *
     * @return void
     */
    public function testAFileIsShownByItsKind(): void
    {
        $config = $this->config();
        $shown  = [
            'photo.png'  => '<img src="/admin/machine/v1/raw/%s/photo.png" alt="photo.png">',
            'song.flac'  => '<audio controls preload="metadata" src="/admin/machine/v1/raw/%s/song.flac"></audio>',
            'film.webm'  => '<video controls preload="metadata" src="/admin/machine/v1/raw/%s/film.webm"></video>',
            'page.html'  => '<pre>&lt;script&gt;alert(1)&lt;/script&gt;</pre>',
            'book.pdf'   => '<a data-no-spa href="/admin/machine/v1/raw/%s/book.pdf">book.pdf</a>',
            'blob'       => AdminText::NoPreview->in(Language::English),
        ];

        foreach ($shown as $name => $expected) {
            $place = MachinePath::resolve($config, $this->subject('/docs/' . $name));
            self::assertNotNull($place);
            $html = FileSection::of($place, $config)?->node()->render(0, Language::English);

            self::assertStringContainsString(sprintf($expected, $this->subject('/docs')), (string) $html, $name);
        }

        $readme = MachinePath::resolve($config, $this->subject('/docs/readme.txt'));
        self::assertNotNull($readme);
        $file = FileSection::of($readme, $config);
        self::assertNotNull($file);
        $page = $file->node()->render(0, Language::English);

        self::assertStringContainsString('href="/admin/machine/v1/rename/', $page);
        self::assertStringContainsString('href="/admin/machine/v1/delete/', $page);
        self::assertStringEndsWith("\n\nhello, machine", $file->render());
        self::assertSame('hello, machine' . "\n", ((array) $file->jsonSerialize())['preview']);
        self::assertStringNotContainsString(
            '/rename/',
            (string) FileSection::of($readme, $this->config(writes: false))?->node()->render(0, Language::English),
        );

        self::assertTrue(new File($this->root . '/docs/big.txt')->write(str_repeat('a', FileSection::PREVIEW + 10)));
        $big = MachinePath::resolve($config, $this->subject('/docs/big.txt'));
        self::assertNotNull($big);
        $cut = FileSection::of($big, $config);

        self::assertStringContainsString(
            AdminText::PreviewCut->in(Language::English),
            (string) $cut?->node()->render(0, Language::English),
        );
        self::assertSame(FileSection::PREVIEW, strlen((string) ((array) $cut?->jsonSerialize())['preview']));

        $gone = MachinePath::resolve($config, $this->subject('/docs/readme.txt'));
        self::assertNotNull($gone);
        unlink($this->root . '/docs/readme.txt');
        self::assertNull(FileSection::of($gone, $config), 'gone before it could be looked at');
    }

    /**
     * The live readings are a table of the moment, with meters where a reading is a share, inside the
     * element that keeps them live; as data they carry the counters beside the facts.
     *
     * @return void
     */
    public function testTheLiveReadingsCarryTheirCounters(): void
    {
        $counters = new MachineCounters(10, 100, 512, 1024, 0, 0, 2048, 4096, 150, 1);
        $stats    = new StatsSection($counters, '/admin/machine/v1/system');
        $html     = $stats->node()->render(0, Language::English);

        self::assertStringContainsString('<machine-stats data-source="/admin/machine/v1/system">', $html);
        self::assertStringContainsString('<meter data-reading="memory" min="0" max="100" value="50"></meter>', $html);
        self::assertStringContainsString('<td data-reading="load">1.50</td>', $html);
        self::assertStringContainsString('<td data-reading="swap"></td>', $html);
        self::assertStringContainsString("memory               512 B of 1.0 KiB (50%)", $stats->render());

        $data = (array) json_decode((string) json_encode($stats), true);

        self::assertSame(MachineArea::Live->value, $data['caption']);
        self::assertSame(1024, $data['counters']['memory-total']);
        self::assertCount(count(MachineReading::cases()), $data['facts']);

        $swapped = new StatsSection(new MachineCounters(swapUsed: 1, swapTotal: 4), '/x');

        self::assertStringContainsString(
            '<meter data-reading="swap" min="0" max="100" value="25"></meter>',
            $swapped->node()->render(0, Language::English),
        );
    }

    /**
     * Processes are a table, the others a paragraph, a text a `<pre>`, and a link a link — each written
     * three ways.
     *
     * @return void
     */
    public function testTheOtherSectionsAreWrittenThreeWays(): void
    {
        $processes = new ProcessSection(new Collection(MachineProcess::class)->with(
            new MachineProcess(1, 'root', 'S', 4096, 0, 0, '<init>'),
        ));

        self::assertStringContainsString('<th>command</th>', $processes->node()->render(0, Language::English));
        self::assertStringContainsString('<td>&lt;init&gt;</td>', $processes->node()->render(0, Language::English));
        self::assertStringContainsString('<init>', $processes->render());
        self::assertSame(1, ((array) $processes->jsonSerialize())['processes'][0]->pid);

        $none = new ProcessSection(new Collection(MachineProcess::class));

        self::assertStringContainsString(
            'does not say what it is running',
            $none->node()->render(0, Language::English),
        );

        $text = new TextSection('output', "a  b\nc\n");

        self::assertStringContainsString("<pre>a  b\nc\n</pre>", $text->node()->render(0, Language::English));
        self::assertSame("output\n  a  b\n  c", $text->render());
        self::assertSame(['caption' => 'output', 'lines' => ['a  b', 'c']], $text->jsonSerialize());

        $link = new LinkSection(AdminText::BackThere, '/admin/machine/v1/files');

        self::assertSame('/admin/machine/v1/files', $link->render());
        self::assertStringContainsString(
            '<a href="/admin/machine/v1/files">Back to where it is</a>',
            $link->node()->render(0, Language::English),
        );
        self::assertSame(['caption' => null, 'lines' => ['/admin/machine/v1/files']], $link->jsonSerialize());
    }

    // ───────────────────────── reading ─────────────────────────

    /**
     * `files` lists a directory, shows a file, lists the roots where there are several, and refuses
     * what is not there, what it may not read, and what is not a file to open.
     *
     * @return void
     */
    public function testFilesListsShowsAndRefuses(): void
    {
        $config = $this->config();

        self::assertFalse(new MachineFiles($config, null)->isWrite());
        self::assertSame(HttpStatusCode::Ok, new MachineFiles($config, $this->subject('/docs'))->handle()->status);
        $shown = new MachineFiles($config, $this->subject('/docs/readme.txt'))->handle();
        self::assertStringStartsWith($this->root . '/docs/readme.txt', $shown->text());
        self::assertStringStartsWith($this->root . "\n", new MachineFiles($config, null)->handle()->text());
        self::assertStringStartsWith(
            MachineArea::Roots->value,
            new MachineFiles($this->config(roots: [$this->root, $this->sandbox . '/outside']), null)->handle()->text(),
        );

        $nowhere = new MachineFiles($config, $this->subject('/out/secret.txt'))->handle();

        self::assertSame(HttpStatusCode::NotFound, $nowhere->status);
        self::assertSame(
            'nothing the machine service reaches is at /' . $this->subject('/out/secret.txt') . "\n",
            $nowhere->text(),
        );

        self::assertTrue(posix_mkfifo($this->root . '/pipe', 0o600));
        $pipe = new MachineFiles($config, $this->subject('/pipe'))->handle();
        self::assertSame(HttpStatusCode::UnprocessableContent, $pipe->status);

        if (posix_geteuid() === 0) {
            self::markTestSkipped('run as root, whom no permission stops reading');
        }

        chmod($this->root . '/empty', 0o000);
        $unreadable = new MachineFiles($config, $this->subject('/empty'))->handle();
        chmod($this->root . '/empty', 0o755);

        self::assertSame(HttpStatusCode::Forbidden, $unreadable->status);
        self::assertStringContainsString('may not read ' . $this->root . '/empty', $unreadable->text());
    }

    /**
     * `raw` shows a file by its kind and hands anything else over to save; `download` always hands it
     * over; neither opens a directory, a pipe, or what it may not read.
     *
     * @return void
     */
    public function testBytesAreShownByKindOrSaved(): void
    {
        $config = $this->config();
        $photo  = new MachineBytes($config, $this->subject('/docs/photo.png'), false)->handle();
        $blob   = new MachineBytes($config, $this->subject('/docs/blob'), false)->handle();
        $saved  = new MachineBytes($config, $this->subject('/docs/photo.png'), true)->handle();

        self::assertFalse(new MachineBytes($config, null, false)->isWrite());
        self::assertSame('image/png', $photo->file?->type->essence());
        self::assertSame('inline', $photo->file?->disposition->render());
        self::assertSame('application/octet-stream', $blob->file?->type->essence());
        self::assertStringStartsWith('attachment', (string) $blob->file?->disposition->render());
        self::assertSame('application/octet-stream', $saved->file?->type->essence());
        self::assertStringStartsWith('attachment; filename="photo.png"', (string) $saved->file?->disposition->render());
        self::assertSame(HttpStatusCode::Ok, $photo->status);
        self::assertSame("\n", $photo->text(), 'a file has no report');
        self::assertSame(['status' => 200, 'sections' => []], $photo->jsonSerialize());

        self::assertSame(HttpStatusCode::NotFound, new MachineBytes($config, null, false)->handle()->status);
        $missing = new MachineBytes($config, $this->subject('/nothing'), true)->handle();
        self::assertSame(HttpStatusCode::NotFound, $missing->status);
        $directory = new MachineBytes($config, $this->subject('/docs'), false)->handle();
        self::assertSame(HttpStatusCode::UnprocessableContent, $directory->status);

        if (posix_geteuid() === 0) {
            self::markTestSkipped('run as root, whom no permission stops reading');
        }

        chmod($this->root . '/docs/readme.txt', 0o000);
        $refused = new MachineBytes($config, $this->subject('/docs/readme.txt'), false)->handle();
        chmod($this->root . '/docs/readme.txt', 0o644);

        self::assertSame(HttpStatusCode::Forbidden, $refused->status);
    }

    /**
     * A file that states its size goes out as one a range can be asked of; one that states none — as
     * `/proc`'s do — is read, and sent as it came.
     *
     * @return void
     */
    public function testAFileWithNoSizeIsReadRatherThanRanged(): void
    {
        $sized = new ResultFile(
            new File($this->root . '/docs/readme.txt'),
            MediaKind::Text->type(new File('x.txt')),
            ContentDisposition::inline(),
        );
        $ranged   = TestRequest::get('/')->with(\Phpanta\Http\RequestHeader::Range, 'bytes=0-4')->request();
        $answered = $sized->response()->answer($ranged);

        self::assertSame(HttpStatusCode::PartialContent, $answered->status());
        self::assertSame('hello', $answered->body());
        self::assertSame(
            'inline',
            $answered->header(\Phpanta\Http\ResponseHeader::ContentDisposition)?->value->render(),
        );
        self::assertSame(
            'noindex, nofollow, noarchive',
            $answered->header(\Phpanta\Http\ResponseHeader::Robots)?->value->render(),
        );

        $unsized = new ResultFile(
            new File('/proc/self/status'),
            MediaKind::Text->type(new File('x.txt')),
            ContentDisposition::inline(),
        );
        $read = $unsized->response()->answer(TestRequest::get('/')->request());

        self::assertSame(HttpStatusCode::Ok, $read->status());
        self::assertStringStartsWith('Name:', $read->body());
        self::assertSame(
            'no-store, private',
            $read->header(\Phpanta\Http\ResponseHeader::CacheControl)?->value->render(),
        );
    }

    /**
     * `system` is the live readings first and then what the probe says; `processes` is what it runs.
     *
     * @return void
     */
    public function testSystemAndProcessesAreWhatTheProbeSays(): void
    {
        $probe = new class () implements MachineProbe {
            /**
             * @return Collection<HealthSection>
             */
            public function sections(): Collection
            {
                return new Collection(HealthSection::class)->with(HealthSection::facts(
                    MachineArea::Host->value,
                    new Collection(HealthFact::class)->with(new HealthFact('hostname', 'box')),
                ));
            }

            /**
             * @return MachineCounters
             */
            public function counters(): MachineCounters
            {
                return new MachineCounters(memoryUsed: 1, memoryTotal: 2);
            }

            /**
             * @return Collection<MachineProcess>
             */
            public function processes(): Collection
            {
                return new Collection(MachineProcess::class)->with(new MachineProcess(7, 'me', 'R', 1, 1, 0, 'php'));
            }
        };

        $system = new MachineSystem($probe);

        self::assertFalse($system->isWrite());
        self::assertStringStartsWith("live\n", $system->handle()->text());
        self::assertStringContainsString("host\n  hostname", $system->handle()->text());

        $processes = new MachineProcesses($probe);

        self::assertFalse($processes->isWrite());
        self::assertStringContainsString('php', $processes->handle()->text());
    }

    // ───────────────────────── writing ─────────────────────────

    /**
     * Files sent are kept under the names they were sent with — each one, unless its name is not a
     * name here or something is there already — and a dry run keeps none.
     *
     * @return void
     */
    public function testUploadKeepsEachFileItMay(): void
    {
        $config = $this->config();
        $docs   = $this->subject('/docs');

        $dry = new MachineUpload(
            $config,
            $docs,
            $this->manifest(MachineAction::Upload, false),
            $this->uploads('new.txt'),
        );

        self::assertFalse($dry->isWrite());
        self::assertStringContainsString('would keep ' . $this->root . '/docs/new.txt (4 B)', $dry->handle()->text());
        self::assertFileDoesNotExist($this->root . '/docs/new.txt');

        $kept = new MachineUpload(
            $config,
            $docs,
            $this->manifest(MachineAction::Upload),
            $this->uploads('new.txt', 'readme.txt', '..'),
        );
        $said = $kept->handle();

        self::assertTrue($kept->isWrite());
        self::assertSame(HttpStatusCode::Ok, $said->status);
        self::assertStringContainsString('kept ' . $this->root . '/docs/new.txt (4 B)', $said->text());
        self::assertStringContainsString('refused readme.txt: something is already there', $said->text());
        self::assertStringContainsString('refused ..: not a name a file may have', $said->text());
        self::assertSame('sent', new File($this->root . '/docs/new.txt')->read());
        self::assertSame(0o644, new File($this->root . '/docs/new.txt')->permissions());
        self::assertSame(
            "hello, machine\n",
            new File($this->root . '/docs/readme.txt')->read(),
            'nothing was replaced',
        );

        $none = new MachineUpload($config, $docs, $this->manifest(MachineAction::Upload), $this->uploads('readme.txt'));

        self::assertSame(HttpStatusCode::UnprocessableContent, $none->handle()->status, 'nothing kept');
        self::assertSame(
            HttpStatusCode::UnprocessableContent,
            new MachineUpload(
                $config,
                $docs,
                $this->manifest(MachineAction::Upload),
                new Collection(Upload::class),
            )->handle()->status,
        );
        self::assertSame(
            HttpStatusCode::UnprocessableContent,
            new MachineUpload(
                $config,
                $this->subject('/docs/readme.txt'),
                $this->manifest(MachineAction::Upload),
                $this->uploads('x'),
            )->handle()->status,
        );
        self::assertSame(
            HttpStatusCode::NotFound,
            new MachineUpload(
                $config,
                $this->subject('/out'),
                $this->manifest(MachineAction::Upload),
                $this->uploads('x'),
            )->handle()->status,
        );

        if (posix_geteuid() === 0) {
            self::markTestSkipped('run as root, whom no permission stops writing');
        }

        chmod($this->root . '/empty', 0o555);
        $refused = new MachineUpload(
            $config,
            $this->subject('/empty'),
            $this->manifest(MachineAction::Upload),
            $this->uploads('x'),
        )->handle();
        chmod($this->root . '/empty', 0o755);

        self::assertStringContainsString('could not keep ' . $this->root . '/empty/x', $refused->text());
    }

    /**
     * A directory is made in a directory, under a name not taken, and a dry run makes none.
     *
     * @return void
     */
    public function testFolderMakesADirectory(): void
    {
        $config = $this->config();
        $dry = new MachineFolder(
            $config,
            $this->subject('/docs'),
            $this->manifest(MachineAction::Folder, false, 'made'),
        );

        self::assertFalse($dry->isWrite());
        self::assertStringContainsString('would make ' . $this->root . '/docs/made', $dry->handle()->text());
        self::assertDirectoryDoesNotExist($this->root . '/docs/made');

        $made = new MachineFolder(
            $config,
            $this->subject('/docs'),
            $this->manifest(MachineAction::Folder, true, 'made'),
        );

        self::assertTrue($made->isWrite());
        self::assertSame(
            "made {$this->root}/docs/made\n\n/admin/machine/v1/files/{$this->subject('/docs/made')}\n",
            $made->handle()->text(),
        );
        self::assertDirectoryExists($this->root . '/docs/made');
        self::assertSame(HttpStatusCode::Conflict, $made->handle()->status, 'taken, now');
        self::assertSame(
            HttpStatusCode::NotFound,
            new MachineFolder(
                $config,
                $this->subject('/out'),
                $this->manifest(MachineAction::Folder, true, 'x'),
            )->handle()->status,
        );
        self::assertSame(
            HttpStatusCode::UnprocessableContent,
            new MachineFolder(
                $config,
                $this->subject('/docs/blob'),
                $this->manifest(MachineAction::Folder, true, 'x'),
            )->handle()->status,
        );

        if (posix_geteuid() === 0) {
            self::markTestSkipped('run as root, whom no permission stops writing');
        }

        chmod($this->root . '/empty', 0o555);
        $refused = new MachineFolder(
            $config,
            $this->subject('/empty'),
            $this->manifest(MachineAction::Folder, true, 'x'),
        )->handle();
        chmod($this->root . '/empty', 0o755);

        self::assertSame(HttpStatusCode::InternalServerError, $refused->status);
        self::assertStringContainsString(
            'could not make ' . $this->root . '/empty/x — the machine service runs as',
            $refused->text(),
        );
    }

    /**
     * An entry is renamed where it is — a link as a link — under a name not taken; a root, and what is
     * not there, are no entries to rename.
     *
     * @return void
     */
    public function testRenameRenamesInPlace(): void
    {
        $config = $this->config();
        $dry = new MachineRename(
            $config,
            $this->subject('/out'),
            $this->manifest(MachineAction::Rename, false, 'away'),
        );

        self::assertFalse($dry->isWrite());
        self::assertStringContainsString(
            'would rename ' . $this->root . '/out to ' . $this->root . '/away',
            $dry->handle()->text(),
        );

        $renamed = new MachineRename(
            $config,
            $this->subject('/out'),
            $this->manifest(MachineAction::Rename, true, 'away'),
        );

        self::assertTrue($renamed->isWrite());
        self::assertSame(HttpStatusCode::Ok, $renamed->handle()->status);
        self::assertTrue(is_link($this->root . '/away'));
        self::assertFileExists($this->sandbox . '/outside/secret.txt', 'the link was renamed, not what it led to');
        self::assertSame(HttpStatusCode::NotFound, $renamed->handle()->status, 'it is gone from where it was');
        self::assertSame(
            HttpStatusCode::Conflict,
            new MachineRename(
                $config,
                $this->subject('/docs/blob'),
                $this->manifest(MachineAction::Rename, true, 'Notes'),
            )->handle()->status,
        );
        self::assertSame(
            HttpStatusCode::NotFound,
            new MachineRename($config, null, $this->manifest(MachineAction::Rename, true, 'x'))->handle()->status,
        );
        self::assertSame(
            HttpStatusCode::NotFound,
            new MachineRename(
                $config,
                (string) $this->subject(''),
                $this->manifest(MachineAction::Rename, true, 'x'),
            )->handle()->status,
            'a root is not an entry',
        );

        if (posix_geteuid() === 0) {
            self::markTestSkipped('run as root, whom no permission stops writing');
        }

        chmod($this->root . '/docs', 0o555);
        $refused = new MachineRename(
            $config,
            $this->subject('/docs/blob'),
            $this->manifest(MachineAction::Rename, true, 'b'),
        )->handle();
        chmod($this->root . '/docs', 0o755);

        self::assertSame(HttpStatusCode::InternalServerError, $refused->status);
    }

    /**
     * A file, a link and an empty directory are removed — the link, never what it points at — and a
     * directory with anything in it is refused.
     *
     * @return void
     */
    public function testDeleteRemovesOneEntryAndNeverATree(): void
    {
        $config = $this->config();

        self::assertStringContainsString(
            'would remove ' . $this->root . '/out',
            new MachineDelete(
                $config,
                $this->subject('/out'),
                $this->manifest(MachineAction::Delete, false),
            )->handle()->text(),
        );
        self::assertTrue(is_link($this->root . '/out'));

        foreach (['/out', '/docs/blob', '/empty'] as $entry) {
            $delete = new MachineDelete($config, $this->subject($entry), $this->manifest(MachineAction::Delete));

            self::assertTrue($delete->isWrite());
            self::assertSame(HttpStatusCode::Ok, $delete->handle()->status, $entry);
            self::assertFalse(file_exists($this->root . $entry) || is_link($this->root . $entry), $entry);
        }

        self::assertFileExists($this->sandbox . '/outside/secret.txt');

        $full = new MachineDelete($config, $this->subject('/docs'), $this->manifest(MachineAction::Delete))->handle();

        self::assertSame(HttpStatusCode::Conflict, $full->status);
        self::assertStringContainsString('is not empty', $full->text());
        self::assertSame(
            HttpStatusCode::NotFound,
            new MachineDelete($config, null, $this->manifest(MachineAction::Delete))->handle()->status,
        );

        if (posix_geteuid() === 0) {
            self::markTestSkipped('run as root, whom no permission stops writing');
        }

        chmod($this->root . '/docs', 0o555);
        $refused = new MachineDelete(
            $config,
            $this->subject('/docs/Notes'),
            $this->manifest(MachineAction::Delete),
        )->handle();
        chmod($this->root . '/docs', 0o755);

        self::assertSame(HttpStatusCode::InternalServerError, $refused->status);
    }

    /**
     * A command runs in a directory and says how it ended, what it printed and what it complained of;
     * a dry run says what would run, where and as whom.
     *
     * @return void
     */
    public function testRunRunsACommandInADirectory(): void
    {
        $config = $this->config(commands: true);
        $dry = new MachineRun(
            $config,
            $this->subject('/docs'),
            $this->manifest(MachineAction::Run, false, command: 'ls'),
        );

        self::assertFalse($dry->isWrite());
        self::assertStringContainsString('would run ls in ' . $this->root . '/docs as ', $dry->handle()->text());

        $run = new MachineRun(
            $config,
            $this->subject('/docs'),
            $this->manifest(MachineAction::Run, command: 'ls; echo oops >&2; exit 3'),
        );
        $text = $run->handle()->text();

        self::assertTrue($run->isWrite());
        self::assertStringContainsString("\nexit      3\n", $text);
        self::assertStringContainsString("output\n  blob\n  book.pdf", $text);
        self::assertStringContainsString("errors\n  oops", $text);

        $loud = new MachineRun(
            $config,
            null,
            $this->manifest(MachineAction::Run, command: 'head -c 1048577 /dev/zero'),
            new CommandRunner(),
        );

        self::assertStringContainsString('it printed more than is kept', $loud->handle()->text());

        $slow = new MachineRun(
            $config,
            null,
            $this->manifest(MachineAction::Run, command: 'sleep 5'),
            new CommandRunner(1),
        );

        self::assertStringContainsString('stopped: it ran out of time', $slow->handle()->text());

        self::assertSame(
            HttpStatusCode::UnprocessableContent,
            new MachineRun(
                $config,
                $this->subject('/docs/blob'),
                $this->manifest(MachineAction::Run, command: 'ls'),
            )->handle()->status,
        );
        self::assertSame(
            HttpStatusCode::NotFound,
            new MachineRun(
                $config,
                $this->subject('/out'),
                $this->manifest(MachineAction::Run, command: 'ls'),
            )->handle()->status,
        );

        if (posix_geteuid() === 0) {
            self::markTestSkipped('run as root, whom no permission keeps out of a directory');
        }

        chmod($this->root . '/empty', 0o000);
        $unstarted = new MachineRun(
            $config,
            $this->subject('/empty'),
            $this->manifest(MachineAction::Run, command: 'ls'),
        )->handle();
        chmod($this->root . '/empty', 0o755);

        self::assertSame(HttpStatusCode::InternalServerError, $unstarted->status);
        self::assertStringContainsString('could not start the shell in ' . $this->root . '/empty', $unstarted->text());
    }

    // ───────────────────────── the actions ─────────────────────────

    /**
     * What a deployment offers is what its file lets it: every read, the writes where it may write,
     * and `run` where it may run commands too — and nothing where it has no file.
     *
     * @return void
     */
    public function testWhatIsOfferedIsWhatTheFileSays(): void
    {
        $values = static fn(?MachineConfig $config): array => MachineAction::offered($config)
            ->map(static fn(MachineAction $action): string => $action->value)
            ->toValues();

        self::assertSame([], $values(null));
        self::assertSame(['system', 'processes', 'files', 'raw', 'download'], $values($this->config(writes: false)));
        self::assertSame(
            ['system', 'processes', 'files', 'raw', 'download', 'upload', 'folder', 'rename', 'delete'],
            $values($this->config()),
        );
        self::assertSame(MachineAction::cases(), MachineAction::offered($this->config(commands: true))->toValues());

        foreach (MachineAction::cases() as $action) {
            self::assertSame($action->writes() ? HttpMethod::Post : HttpMethod::Get, $action->method());
            self::assertTrue($action->fromBrowser());
            self::assertNotSame($action->describe()->in(Language::English), $action->describe()->in(Language::German));
            self::assertSame(
                $action->writes(),
                $action->fields()->first(static fn(ActionField $field): bool => $field === ActionField::Apply) !== null,
            );
        }

        self::assertFalse(MachineAction::System->takesPath());
        self::assertTrue(MachineAction::Files->takesPath());
        self::assertSame([ActionField::Files, ActionField::Apply], MachineAction::Upload->fields()->toValues());
        self::assertSame('/admin/machine/v1/files', MachineAction::Files->href(null));
        self::assertSame('/admin/machine/v1/raw/a%20b/c.flac', MachineAction::Raw->href('a b/c.flac'));
    }

    /**
     * A refusal names the place and why.
     *
     * @return void
     */
    public function testARefusalSaysWhereAndWhy(): void
    {
        $place = MachinePath::resolve($this->config(), $this->subject('/docs'));
        self::assertNotNull($place);

        self::assertSame(HttpStatusCode::NotFound, MachineRefusal::nowhere(null)->status);
        self::assertSame("nothing the machine service reaches is at /\n", MachineRefusal::nowhere(null)->text());
        self::assertSame($this->root . "/docs is not a directory\n", MachineRefusal::notDirectory($place)->text());
        self::assertSame(HttpStatusCode::Conflict, MachineRefusal::taken('/x')->status);
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * What the sandbox's root switches on.
     *
     * @param list<string>|null $roots
     * @param bool              $writes
     * @param bool              $commands
     * @return MachineConfig
     */
    private function config(?array $roots = null, bool $writes = true, bool $commands = false): MachineConfig
    {
        $config = MachineConfig::parse((string) json_encode([
            'roots'    => $roots ?? [$this->root],
            'writes'   => $writes,
            'commands' => $commands,
        ]));

        self::assertNotNull($config);

        return $config;
    }

    /**
     * How the admin addresses $relative under the root.
     *
     * @param string $relative The address under the root — `/docs`, or `''` for the root.
     * @return string|null
     */
    private function subject(string $relative): ?string
    {
        return ltrim($this->root . $relative, '/');
    }

    /**
     * A write's manifest.
     *
     * @param MachineAction $action
     * @param bool          $apply
     * @param string        $target
     * @param string        $command
     * @return MachineManifest
     */
    private function manifest(
        MachineAction $action,
        bool $apply = true,
        string $target = 'x',
        string $command = 'true',
    ): MachineManifest {
        return MachineManifest::parse(
            (string) json_encode(['apply' => $apply, 'target' => $target, 'command' => $command]),
            $action,
        );
    }

    /**
     * Files a browser sent under $names, each holding `sent`.
     *
     * @param string ...$names
     * @return Collection<Upload>
     */
    private function uploads(string ...$names): Collection
    {
        $uploads = new Collection(Upload::class);

        foreach ($names as $name) {
            $file = new File($this->sandbox . '/upload-' . bin2hex(random_bytes(4)));
            self::assertTrue($file->write('sent'));
            $uploads = $uploads->with(new Upload($name, $file, 4));
        }

        return $uploads;
    }
}
