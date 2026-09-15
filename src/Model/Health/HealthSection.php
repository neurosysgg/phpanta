<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

use Phpanta\Http\Api\ResultKey;
use Phpanta\Http\Api\ResultSection;
use Phpanta\Support\BareString;
use Phpanta\Support\Collection;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;

/**
 * The HealthSection class. One heading of an admin answer, and whatever sits under it.
 *
 * **Two constructors, because the answers have two kinds of section.** Nearly all of them are
 * facts — a name in a column and a value beside it — and a few are lines of text with no name to
 * give them: the tail of an error log, what a push wrote, a tally. A section keeps which kind it is
 * and what it holds, unrendered, so the one section can be written three ways — as the text a
 * terminal reads ({@link self::render()}), as data ({@link self::jsonSerialize()}), and as part of a
 * page ({@link self::node()}) — without any of them parsing another.
 *
 * **The caption may be null**, for a block that is a paragraph rather than a heading: a push's
 * report and a health check's tally have always been written that way, flush left with no caption
 * over them, and the text they render is the text they rendered before they were sections.
 *
 * The indent lives here rather than on {@link HealthFact}. A section places its lines; a fact only
 * decides what one says. Left on the fact, the log tail would have needed the same number written a
 * second time in a second class, which is the failure this codebase names everywhere else.
 *
 * {@link self::facts()} takes a collection and {@link self::lines()} takes a variadic, and the
 * difference is not inconsistency: most fact sections are built by filtering and mapping an
 * existing set — the declared requirements, the app's data files, what the engine
 * lists — so a variadic there would mean spreading a collection only to have it rebuilt, where the
 * log's lines are written out at their one call site and a variadic is a check PHP makes for free.
 *
 * The caption is a plain string and stays one. It is each answer's own copy rather than a
 * vocabulary anything else reads: nothing selects on it and nothing parses it. The one exception
 * is a health check's, which is an {@link Area}'s value — and there the area is what names it.
 */
#[BareString(
    'string',
    "a scalar type name standing in a class-string's place, spelled the way get_debug_type() "
    . 'spells it. Support\\TypedItems and Model\\Update\\UpdateReport carry the same excuse for '
    . 'the same word; this is the collection of lines a section of text is, and join() will only '
    . 'join a collection that says it holds strings.',
)]
final readonly class HealthSection implements ResultSection
{
    /** How far a captioned section's lines sit under its caption. */
    private const string INDENT = '  ';

    /**
     * Constructs an instance of {@link self}.
     *
     * Private, so a section is one of the two shapes below.
     *
     * @param string|null $caption Null for a block with no heading.
     * @param Collection<HealthFact>|null $facts The facts, for a section of facts; null for text.
     * @param Collection<string> $lines The lines, for a section of text; empty for facts.
     */
    private function __construct(
        private ?string    $caption,
        private ?Collection $facts,
        private Collection $lines,
    ) {}

    /**
     * A section of named values.
     *
     * Under a caption, the name column is {@link HealthFact::COLUMN} unless a name in this section
     * is longer, and then it is that name's width plus one. Per section rather than per response,
     * because a section is what a reader runs an eye down — and in practice the only section that
     * widens is `capability v1 settings`'s, which is alone in its response.
     *
     * With no caption the column is exactly the longest name, which is what a block of three short
     * facts that is the whole answer — `update v1 version`'s — has always been written as.
     *
     * @param string|null $caption
     * @param Collection<HealthFact> $facts
     * @return self
     */
    public static function facts(?string $caption, Collection $facts): self
    {
        return new self($caption, $facts, new Collection('string'));
    }

    /**
     * A section of plain text — the error log's own lines, a push's report, a tally.
     *
     * @param string|null $caption
     * @param string ...$lines
     * @return self
     */
    public static function lines(?string $caption, string ...$lines): self
    {
        return new self($caption, null, new Collection('string')->with(...$lines));
    }

    /**
     * A whole response body of sections: each rendered, a blank line between them, and the newline
     * every body here ends in — this kind of section or any other.
     *
     * @param ResultSection ...$sections
     * @return string
     */
    public static function document(ResultSection ...$sections): string
    {
        return new Collection(ResultSection::class)->with(...$sections)
            ->map(static fn(ResultSection $section): string => $section->render())
            ->join("\n\n") . "\n";
    }

    /**
     * The caption and everything under it, with no trailing newline — joining sections is
     * {@link self::document()}'s to do, the way joining lines is this method's.
     *
     * @return string
     */
    public function render(): string
    {
        $indent = $this->caption === null ? '' : self::INDENT;
        $lines  = $this->text()->map(static fn(string $line): string => $indent . $line)->join("\n");

        return $this->caption === null ? $lines : $this->caption . "\n" . $lines;
    }

    /**
     * The section as data: its caption, and either its facts or its lines — never both, so a reader
     * knows which kind it holds by which key is there.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return $this->facts === null
            ? [ResultKey::Caption->value => $this->caption, ResultKey::Lines->value => $this->lines->toValues()]
            : [ResultKey::Caption->value => $this->caption, ResultKey::Facts->value => $this->facts->toValues()];
    }

    /**
     * The section as part of a page: its caption as a heading, then a table of its facts — a
     * verdict in a column of its own where a fact has one — or a list of its lines.
     *
     * @return Node
     */
    public function node(): Node
    {
        $section = new Element(HtmlTag::Section);

        if ($this->caption !== null) {
            $section = $section->containing(new Element(HtmlTag::H2)->containing($this->caption));
        }

        if ($this->facts === null) {
            return $section->containing(new Element(HtmlTag::Ul)->containing(...$this->lines
                ->map(static fn(string $line): Node => new Element(HtmlTag::Li)->containing($line))
                ->toValues()));
        }

        return $section->containing(new Element(HtmlTag::Table)->containing(...$this->facts
            ->map(static fn(HealthFact $fact): Node => $fact->row())
            ->toValues()));
    }

    /**
     * The section's lines, unindented: its facts in their column, or its text as it was given.
     *
     * @return Collection<string>
     */
    private function text(): Collection
    {
        if ($this->facts === null) {
            return $this->lines;
        }

        $column = $this->caption === null ? 0 : HealthFact::COLUMN;

        foreach ($this->facts as $fact) {
            $column = max($column, strlen($fact->name) + ($this->caption === null ? 0 : 1));
        }

        return $this->facts->map(static fn(HealthFact $fact): string => $fact->render($column));
    }
}
