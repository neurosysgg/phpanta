<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

use Phpanta\Support\BareString;
use Phpanta\Support\Collection;

/**
 * The HealthSection class. One heading of a `capability` or `health` answer, and whatever sits
 * under it.
 *
 * **Two constructors, because the answers have two kinds of section and only one kind of shape.**
 * Nearly all of them are facts — a name in a column and a value beside it — and one is the tail of
 * an error log, which is lines of somebody else's text with no name to give them. Both end as a
 * caption and an indented block, so what this holds is the block: a `Collection<string>` of
 * rendered lines, with {@link self::facts()} and {@link self::lines()} the two ways in.
 *
 * That is why the indent lives here rather than on {@link HealthFact}. A section places its lines;
 * a fact only decides what one says. Left on the fact, the log tail would have needed the same
 * number written a second time in a second class, which is the failure this codebase names
 * everywhere else.
 *
 * {@link self::facts()} takes a collection and {@link self::lines()} takes a variadic, and the
 * difference is not inconsistency: most fact sections are built by filtering and mapping an
 * existing set — the declared requirements, {@link \NeuroSYS\DataFile::cases()}, what the engine
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
    . 'the same word; this is the collection of rendered lines a section is, and join() will only '
    . 'join a collection that says it holds strings.',
)]
final readonly class HealthSection
{
    /** How far a section's lines sit under its caption. */
    private const string INDENT = '  ';

    /**
     * Constructs an instance of {@link self}.
     *
     * Private, so a section is one of the two shapes below rather than any block of text that
     * happens to be handed in already indented.
     *
     * @param string $caption
     * @param Collection<string> $lines Rendered and indented, in the order they should be read.
     */
    private function __construct(private string $caption, private Collection $lines) {}

    /**
     * A section of named values.
     *
     * The name column is {@link HealthFact::COLUMN} unless a name in this section is longer, and
     * then it is that name's width plus one. Per section rather than per response, because a
     * section is what a reader runs an eye down — and in practice the only section that widens is
     * `capability v1 settings`'s, which is alone in its response.
     *
     * @param string $caption
     * @param Collection<HealthFact> $facts
     * @return self
     */
    public static function facts(string $caption, Collection $facts): self
    {
        $column = HealthFact::COLUMN;

        foreach ($facts as $fact) {
            $column = max($column, strlen($fact->name) + 1);
        }

        return new self(
            $caption,
            $facts->map(static fn(HealthFact $fact): string => self::INDENT . $fact->render($column)),
        );
    }

    /**
     * A section of plain text — today, the error log's own lines.
     *
     * @param string $caption
     * @param string ...$lines
     * @return self
     */
    public static function lines(string $caption, string ...$lines): self
    {
        return new self(
            $caption,
            new Collection('string')->with(...$lines)
                ->map(static fn(string $line): string => self::INDENT . $line),
        );
    }

    /**
     * A whole response body of sections: each rendered, a blank line between them, and the newline
     * every body on this site ends in.
     *
     * @param self ...$sections
     * @return string
     */
    public static function document(self ...$sections): string
    {
        return new Collection(self::class)->with(...$sections)
            ->map(static fn(self $section): string => $section->render())
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
        return $this->caption . "\n" . $this->lines->join("\n");
    }
}
