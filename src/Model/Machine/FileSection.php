<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use Phpanta\Http\Api\MachineAction;
use Phpanta\Http\Api\ResultKey;
use Phpanta\Http\Api\ResultSection;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Support\Charset;
use Phpanta\Support\Collection;
use Phpanta\Text\AdminText;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\LinkAttribute;
use Phpanta\View\Html\MediaPreload;
use Phpanta\View\Html\Node;

/**
 * The FileSection class. A file the `machine` service shows: where it is, what it is, the ways to
 * open, save, rename or remove it, and — where a browser can show it — the file itself.
 *
 * **Shown by kind, from its own address.** A picture is an `<img>`, a recording an `<audio>` and a
 * film a `<video>`, each fetching the file from `raw`, which a range lets a player seek in; text is
 * read here, at most {@link self::PREVIEW} bytes of it, and written as text into a `<pre>` — escaped
 * like any other text, whatever it holds. A file of any other kind is described and offered to save.
 */
final readonly class FileSection implements ResultSection
{
    /** The most of a text file shown, in bytes: a quarter of a mebibyte. */
    public const int PREVIEW = 262_144;

    /** What stands between two things one may do with it. */
    private const string BETWEEN = ' · ';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param MachinePath   $place   The file.
     * @param MachineEntry  $entry   What `lstat()` says of it.
     * @param MediaKind     $kind    What it is to a browser.
     * @param string|null   $preview Its text, where it is text; null otherwise.
     * @param bool          $cut     Whether the text is only its beginning.
     * @param MachineConfig $config  What the service may do here.
     */
    private function __construct(
        private MachinePath   $place,
        private MachineEntry  $entry,
        private MediaKind     $kind,
        private ?string       $preview,
        private bool          $cut,
        private MachineConfig $config,
    ) {}

    /**
     * The file at $place, or null where it is gone before it could be looked at.
     *
     * @param MachinePath   $place
     * @param MachineConfig $config
     * @return self|null
     */
    public static function of(MachinePath $place, MachineConfig $config): ?self
    {
        $entry = MachineEntry::at($place->path);

        if ($entry === null) {
            return null;
        }

        $kind = MediaKind::of($place->file());
        $read = $kind === MediaKind::Text ? $place->file()->read(self::PREVIEW + 1) : null;
        $cut  = $read !== null && strlen($read) > self::PREVIEW;
        $utf8 = Charset::Utf8->canonical();
        $text = $read === null ? null : mb_scrub($cut ? mb_strcut($read, 0, self::PREVIEW, $utf8) : $read, $utf8);

        return new self($place, $entry, $kind, $text, $cut, $config);
    }

    /**
     * @return string
     */
    public function render(): string
    {
        $facts = $this->facts()->render();

        return $this->preview === null ? $facts : $facts . "\n\n" . rtrim($this->preview, "\n");
    }

    /**
     * @return Node
     */
    public function node(): Node
    {
        $children = [Whereabouts::of($this->place), $this->offered(), $this->facts()->node()];

        $children[] = match ($this->kind) {
            MediaKind::Image => new Element(HtmlTag::Img)
                ->attr(HtmlAttribute::Src, $this->raw())
                ->attr(HtmlAttribute::Alt, $this->entry->name),
            MediaKind::Audio => new Element(HtmlTag::Audio)
                ->attr(HtmlAttribute::Controls, true)
                ->attr(HtmlAttribute::Preload, MediaPreload::Metadata)
                ->attr(HtmlAttribute::Src, $this->raw()),
            MediaKind::Video => new Element(HtmlTag::Video)
                ->attr(HtmlAttribute::Controls, true)
                ->attr(HtmlAttribute::Preload, MediaPreload::Metadata)
                ->attr(HtmlAttribute::Src, $this->raw()),
            MediaKind::Text  => new Element(HtmlTag::Pre)->containing((string) $this->preview),
            MediaKind::Pdf   => new Element(HtmlTag::P)->containing(
                new Element(HtmlTag::A)
                    ->attr(LinkAttribute::NoSpa, true)
                    ->attr(HtmlAttribute::Href, $this->raw())
                    ->containing($this->entry->name),
            ),
            MediaKind::Other => new Element(HtmlTag::P)->containing(AdminText::NoPreview),
        };

        if ($this->cut) {
            $children[] = new Element(HtmlTag::P)->containing(AdminText::PreviewCut);
        }

        return new Element(HtmlTag::Section)->containing(...$children);
    }

    /**
     * The file as data: its facts, what kind it is, and the text it begins with where it is text.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return [
            ...(array) $this->facts()->jsonSerialize(),
            ResultKey::Kind->value    => $this->kind->value,
            ResultKey::Preview->value => $this->preview,
        ];
    }

    /**
     * What there is to say of it, under its path.
     *
     * @return HealthSection
     */
    private function facts(): HealthSection
    {
        $facts = new Collection(HealthFact::class)->with(
            new HealthFact(MachineFact::Size->value, Measure::bytes($this->entry->size)),
            new HealthFact(MachineFact::Modified->value, Measure::moment($this->entry->modified)),
            new HealthFact(MachineFact::Permissions->value, $this->entry->permissions()),
            new HealthFact(MachineFact::Owner->value, $this->entry->owner),
            new HealthFact(MachineFact::Type->value, $this->kind->type($this->place->file())->essence()),
        );

        return HealthSection::facts($this->place->path, $facts);
    }

    /**
     * Opening it, saving it, and — where the service may write — renaming and removing it.
     *
     * @return Element
     */
    private function offered(): Element
    {
        $subject = $this->place->subject();
        $links   = [
            new Element(HtmlTag::A)
                ->attr(LinkAttribute::NoSpa, true)
                ->attr(HtmlAttribute::Href, $this->raw())
                ->containing(AdminText::Open),
            self::BETWEEN,
            new Element(HtmlTag::A)
                ->attr(HtmlAttribute::Href, MachineAction::Download->href($subject))
                ->attr(HtmlAttribute::Download, true)
                ->containing(AdminText::Save),
        ];

        if ($this->config->writes) {
            $links[] = self::BETWEEN;
            $links[] = new Element(HtmlTag::A)
                ->attr(HtmlAttribute::Href, MachineAction::Rename->href($subject))
                ->containing(AdminText::Rename);
            $links[] = self::BETWEEN;
            $links[] = new Element(HtmlTag::A)
                ->attr(HtmlAttribute::Href, MachineAction::Delete->href($subject))
                ->containing(AdminText::Remove);
        }

        return new Element(HtmlTag::P)->containing(...$links);
    }

    /**
     * Where its bytes are, to be shown.
     *
     * @return string
     */
    private function raw(): string
    {
        return MachineAction::Raw->href($this->place->subject());
    }
}
