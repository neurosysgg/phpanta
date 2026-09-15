<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use Phpanta\Http\Api\MachineAction;
use Phpanta\Http\Api\ResultKey;
use Phpanta\Http\Api\ResultSection;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Support\Collection;
use Phpanta\Text\AdminText;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\InputType;
use Phpanta\View\Html\MachineAttribute;
use Phpanta\View\Html\MachineTag;
use Phpanta\View\Html\Node;

/**
 * The DirectorySection class. A directory the `machine` service shows: where it is, what may be done
 * there, and its entries — or, with no directory, the roots the service may walk.
 *
 * **A page is a table of links.** Each entry opens — a directory to its listing, a file to what it
 * holds — and a file can be saved from its row. What may be written here is offered as links to each
 * write's form, and only where `data/machine.json` lets the service write: a form is what taps the
 * passkey, so a write is two clicks and a tap, never one. `<machine-filter>` hides the rows whose
 * names do not hold what is typed, and without its script the page is the same listing, whole.
 *
 * **At most {@link self::MOST} entries are listed**, directories first and then by name, and the page
 * says when there are more; the data says how many there are.
 */
final readonly class DirectorySection implements ResultSection
{
    /** The most entries a listing shows. */
    public const int MOST = 2000;

    /** What stands between two things one may do here. */
    private const string BETWEEN = ' · ';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param MachinePath|null        $place   The directory, or null for the roots.
     * @param Collection<MachineEntry> $entries What is listed, in order.
     * @param int                     $held    How many entries there are.
     * @param MachineConfig           $config  What the service may do here.
     */
    private function __construct(
        private ?MachinePath  $place,
        private Collection    $entries,
        private int           $held,
        private MachineConfig $config,
    ) {}

    /**
     * The directory $place is.
     *
     * @param MachinePath   $place
     * @param MachineConfig $config
     * @return self
     */
    public static function of(MachinePath $place, MachineConfig $config): self
    {
        $entries = MachineEntry::in($place->path);
        $listed  = [];

        foreach ($entries as $entry) {
            if (count($listed) === self::MOST) {
                break;
            }

            $listed[] = $entry;
        }

        return new self($place, new Collection(MachineEntry::class)->with(...$listed), $entries->count(), $config);
    }

    /**
     * The roots, each by its whole path, where there are several to choose from.
     *
     * @param MachineConfig $config
     * @return self
     */
    public static function roots(MachineConfig $config): self
    {
        $entries = new Collection(MachineEntry::class);

        foreach ($config->roots() as $root) {
            $entry = MachineEntry::at($root->path);

            if ($entry !== null) {
                $entries = $entries->with($entry->named($root->path));
            }
        }

        return new self(null, $entries, $entries->count(), $config);
    }

    /**
     * @return string
     */
    public function render(): string
    {
        return $this->text()->render();
    }

    /**
     * @return Node
     */
    public function node(): Node
    {
        $children = [];

        if ($this->place !== null) {
            $children[] = Whereabouts::of($this->place);
            $offered    = $this->offered($this->place);

            if ($offered !== null) {
                $children[] = $offered;
            }
        }

        $rows = $this->entries->map(fn(MachineEntry $entry): Node => self::row($entry))->toValues();
        $up   = $this->place?->parent();

        if ($up !== null) {
            $rows = [self::up($up), ...$rows];
        }

        $children[] = new Element(MachineTag::Filter)->attr(HtmlAttribute::Hidden, true)->containing(
            new Element(HtmlTag::Input)
                ->attr(HtmlAttribute::Type, InputType::Search)
                ->attr(HtmlAttribute::Placeholder, AdminText::Filter)
                ->attr(HtmlAttribute::AriaLabel, AdminText::Filter),
        );
        $children[] = new Element(HtmlTag::Table)->containing(self::heading(), ...$rows);

        if ($this->held > $this->entries->count()) {
            $children[] = new Element(HtmlTag::P)->containing(AdminText::MoreEntries);
        }

        return new Element(HtmlTag::Section)->containing(...$children);
    }

    /**
     * The directory as data: its path and its lines, as a section of text, and each entry with where
     * it opens — and how many it holds.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        $entries = [];

        foreach ($this->entries as $entry) {
            $entries[] = [
                ...(array) $entry->jsonSerialize(),
                ResultKey::Href->value => MachineAction::Files->href(MachinePath::subjectOf($entry->path)),
            ];
        }

        return [
            ...(array) $this->text()->jsonSerialize(),
            ResultKey::Entries->value => $entries,
            ResultKey::Held->value    => $this->held,
        ];
    }

    /**
     * The listing as a section of text, one line per entry.
     *
     * @return HealthSection
     */
    private function text(): HealthSection
    {
        $caption = $this->place?->path ?? MachineArea::Roots->value;

        return HealthSection::lines(
            $caption,
            ...$this->entries->map(static fn(MachineEntry $entry): string => $entry->line())->toValues(),
        );
    }

    /**
     * What may be done in $place — keeping files, making a directory, running a command — as links to
     * each write's form, or null where nothing may.
     *
     * @param MachinePath $place
     * @return Element|null
     */
    private function offered(MachinePath $place): ?Element
    {
        $links = [];

        foreach (MachineAction::offered($this->config) as $action) {
            $label = match ($action) {
                MachineAction::Upload => AdminText::UploadHere,
                MachineAction::Folder => AdminText::NewFolder,
                MachineAction::Run    => AdminText::RunHere,
                default               => null,
            };

            if ($label === null) {
                continue;
            }

            if ($links !== []) {
                $links[] = self::BETWEEN;
            }

            $links[] = new Element(HtmlTag::A)
                ->attr(HtmlAttribute::Href, $action->href($place->subject()))
                ->containing($label);
        }

        return $links === [] ? null : new Element(HtmlTag::P)->containing(...$links);
    }

    /**
     * The row naming each column.
     *
     * @return Element
     */
    private static function heading(): Element
    {
        return new Element(HtmlTag::Tr)->containing(
            new Element(HtmlTag::Th)->containing(AdminText::ColumnName),
            new Element(HtmlTag::Th)->containing(AdminText::ColumnSize),
            new Element(HtmlTag::Th)->containing(AdminText::ColumnModified),
            new Element(HtmlTag::Th)->containing(AdminText::ColumnMode),
            new Element(HtmlTag::Th)->containing(AdminText::ColumnOwner),
            new Element(HtmlTag::Th),
        );
    }

    /**
     * The row leading up, to the directory this one is in. It is no entry, so the filter leaves it.
     *
     * @param MachinePath $up
     * @return Element
     */
    private static function up(MachinePath $up): Element
    {
        $link = new Element(HtmlTag::A)
            ->attr(HtmlAttribute::Href, MachineAction::Files->href($up->subject()))
            ->containing('..');

        return new Element(HtmlTag::Tr)->containing(new Element(HtmlTag::Td)->containing($link));
    }

    /**
     * One entry's row: a link that opens it, what it is, and — for a file — a way to save it.
     *
     * @param MachineEntry $entry
     * @return Element
     */
    private static function row(MachineEntry $entry): Element
    {
        $subject = MachinePath::subjectOf($entry->path);
        $save    = $entry->opens ? new Element(HtmlTag::Td) : new Element(HtmlTag::Td)->containing(
            new Element(HtmlTag::A)
                ->attr(HtmlAttribute::Href, MachineAction::Download->href($subject))
                ->attr(HtmlAttribute::Download, true)
                ->containing(AdminText::Save),
        );

        return new Element(HtmlTag::Tr)->attr(MachineAttribute::Entry, mb_strtolower($entry->name))->containing(
            new Element(HtmlTag::Td)->containing(
                new Element(HtmlTag::A)
                    ->attr(HtmlAttribute::Href, MachineAction::Files->href($subject))
                    ->containing($entry->opens ? $entry->name . '/' : $entry->name),
            ),
            new Element(HtmlTag::Td)->containing($entry->opens ? '' : Measure::bytes($entry->size)),
            new Element(HtmlTag::Td)->containing(Measure::moment($entry->modified)),
            new Element(HtmlTag::Td)->containing($entry->permissions()),
            new Element(HtmlTag::Td)->containing($entry->owner),
            $save,
        );
    }
}
