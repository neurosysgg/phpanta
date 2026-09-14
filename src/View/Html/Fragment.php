<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

use Generator;
use Phpanta\Support\Collection;
use Phpanta\Text\Language;

/**
 * The Fragment class. Several nodes with no element around them.
 *
 * What a list of cards is, and what a view's content is when the page has more than one top-level
 * section. Renders its children one per line at its own depth, so a fragment placed inside an
 * {@link Element} indents like any other child — unless it holds text, or its parent has put its
 * children on one line, and then it keeps to one line too: see {@link self::renderInline()}.
 */
final readonly class Fragment implements Node
{
    /** @var Collection<Node> */
    private Collection $nodes;

    /**
     * Constructs an instance of {@link self} from the given nodes, in order.
     *
     * @param Node ...$nodes
     */
    public function __construct(Node ...$nodes)
    {
        $this->nodes = new Collection(Node::class)->with(...$nodes);
    }

    /**
     * Builds a fragment by mapping $items through $node.
     *
     * @template T
     * @param iterable<T>          $items
     * @param callable(T): Node    $node
     * @return self
     */
    public static function each(iterable $items, callable $node): self
    {
        $nodes = [];

        foreach ($items as $item) {
            $nodes[] = $node($item);
        }

        return new self(...$nodes);
    }

    /**
     * True if $node puts text on the line it is rendered on: a {@link Text}, a
     * {@link TranslatedText}, or a fragment holding either.
     *
     * The question {@link Element::renderChildren()} asks to decide whether its children go on one
     * line, and the one this class asks of its own nodes — one answer, so the two cannot disagree
     * about what counts as text.
     *
     * @param Node $node
     * @return bool
     */
    public static function writesText(Node $node): bool
    {
        return $node instanceof Text
            || $node instanceof TranslatedText
            || $node instanceof Sentence
            || ($node instanceof self && $node->isInline());
    }

    /**
     * True if any of this fragment's nodes is text, which makes the whole of it inline content.
     *
     * @return bool
     */
    public function isInline(): bool
    {
        return $this->nodes->first(self::writesText(...)) !== null;
    }

    /**
     * @param int           $depth
     * @param Language|null $language
     * @return string
     */
    public function render(int $depth = 0, ?Language $language = null): string
    {
        // A newline between inline nodes is a space the browser renders — the rule
        // Element::renderChildren() keeps for an element's children, kept here for a fragment's.
        if ($this->isInline()) {
            return $this->renderInline($depth, $language);
        }

        return self::lines($this->nodes, $depth, $language);
    }

    /**
     * $nodes one per line at $depth, a fragment among them opened into its own nodes — except two
     * phrasing elements side by side, which share a line.
     *
     * A newline beside a block is whitespace at the edge of a line, which the browser drops; between
     * `<strong>a</strong>` and `<em>b</em>` it is a space inside a word. {@link Element} lays out its
     * children through this, so an element and a fragment cannot disagree about which is which, and
     * a fragment opened into its parent is written exactly as it would have written itself.
     *
     * @param Collection<Node> $nodes
     * @param int              $depth
     * @param Language|null    $language
     * @return string
     */
    public static function lines(Collection $nodes, int $depth, ?Language $language): string
    {
        $rendered = '';
        $previous = null;

        foreach (self::opened($nodes) as $node) {
            if ($previous !== null) {
                $rendered .= self::shareALine($previous, $node) ? '' : "\n" . str_repeat('  ', $depth);
            }

            $rendered .= $node->render($depth, $language);
            $previous  = $node;
        }

        return $rendered;
    }

    /**
     * $nodes with every fragment among them replaced by its own nodes, all the way down.
     *
     * @param Collection<Node> $nodes
     * @return Generator<Node>
     */
    private static function opened(Collection $nodes): Generator
    {
        foreach ($nodes as $node) {
            if ($node instanceof self) {
                yield from self::opened($node->nodes);
            } else {
                yield $node;
            }
        }
    }

    /**
     * True if $before and $after are both phrasing elements, so a newline between them would be a
     * space on the page.
     *
     * @param Node $before
     * @param Node $after
     * @return bool
     */
    private static function shareALine(Node $before, Node $after): bool
    {
        return $before instanceof Element && $before->isPhrasing()
            && $after instanceof Element && $after->isPhrasing();
    }

    /**
     * Renders the nodes on one line, for a parent that has put its children on one.
     *
     * An element whose children include text renders them on a single line, and a fragment among
     * them was the one child that did not know it: it broke its nodes onto lines of their own, and
     * each newline it wrote was a space between two links on the page. A fragment inside this one
     * is on the same line, so it is asked the same way.
     *
     * @param int           $depth
     * @param Language|null $language
     * @return string
     */
    public function renderInline(int $depth, ?Language $language): string
    {
        return $this->nodes
            ->map(static fn(Node $node): string => $node instanceof self
                ? $node->renderInline($depth, $language)
                : $node->render($depth, $language))
            ->join('');
    }
}
