<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\Support\BareArray;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\Node;
use Phpanta\View\Html\Text;
use PhpToken;

/**
 * What a code sample is written in, and how it is highlighted: a `<code-block>` of `<code-line>`s,
 * each piece a reader looks for set in its {@link CodeTag}, everything else as plain text, and
 * nothing added, dropped or moved.
 *
 * Small on purpose, because the samples are few and known. PHP is read by PHP's own tokenizer,
 * which already knows every keyword; a shell line and a tree line are simple enough for a pattern.
 * A sample that needed more — heredocs in a shell line, say — would be the moment to say so here.
 */
enum SampleLanguage: string
{
    case Php   = 'php';
    case Shell = 'shell';
    case Tree  = 'tree';

    /** A comment in a shell line: a `#` that opens the line or follows a space, to its end. */
    private const string SHELL_COMMENT = '/(?:^|(?<= ))#.*\z/';

    /** A tree line: its branches, its entry, and the note after a gap of two spaces or more. */
    private const string TREE_LINE = '/^([ ├└│─]*)(.*?)(?:( {2,})(.*))?\z/u';

    /** A word PHP spells a keyword with — `final`, `fn`, `__DIR__`. */
    private const string WORD = '/^[a-z_]+\z/i';

    /** Where a line ends, among the pieces the readers below produce. */
    private const string NEWLINE = "\n";

    /**
     * $text as a `<code-block>` in this language, one `<code-line>` per line.
     *
     * **Whitespace stays text, and so do the newlines between the lines.** The tree renders an
     * element's children on one line whenever any of them is text, and breaks them onto lines of
     * their own when none is — which, inside a block that keeps its whitespace, the reader would
     * see. The newlines keep the block on one line; see {@link self::line()} for a line.
     *
     * @param string $text
     * @return Element
     */
    public function highlight(string $text): Element
    {
        $pieces = match ($this) {
            self::Php   => self::php($text),
            self::Shell => self::lines($text, self::shellLine(...)),
            self::Tree  => self::lines($text, self::treeLine(...)),
        };

        $lines = [[]];

        foreach ($pieces as $piece) {
            if ($piece instanceof Text && $piece->text === self::NEWLINE) {
                $lines[] = [];
            } else {
                $lines[array_key_last($lines)][] = $piece;
            }
        }

        $children = [];

        foreach ($lines as $number => $nodes) {
            if ($number > 0) {
                $children[] = new Text(self::NEWLINE);
            }

            $children[] = self::line($nodes);
        }

        return new Element(CodeTag::Block)
            ->attr(CodeAttribute::Language, $this)
            ->containing(...self::onOneLine($children));
    }

    /**
     * One `<code-line>`.
     *
     * @param list<Node> $nodes
     * @return Element
     */
    #[BareArray('the line\'s nodes, spread into containing(), which is a variadic')]
    private static function line(array $nodes): Element
    {
        return new Element(CodeTag::Line)->containing(...self::onOneLine($nodes));
    }

    /**
     * $nodes, with an empty text in front where none of them is text — so the tree renders them on
     * one line rather than each on a line of its own. A one-line sample's block holds a single line
     * and no newline, and a line of tokens alone would hold no text either.
     *
     * @param list<Node> $nodes
     * @return list<Node>
     */
    #[BareArray('a parent\'s children, spread into containing(), which is a variadic')]
    private static function onOneLine(array $nodes): array
    {
        foreach ($nodes as $node) {
            if ($node instanceof Text) {
                return $nodes;
            }
        }

        return $nodes === [] ? $nodes : [new Text(''), ...$nodes];
    }

    /**
     * PHP, token by token. The sample is tokenized behind an open tag, which is dropped again: the
     * open tag's token is `<?php ` with its space, so the rest is the sample exactly. A token that
     * spans lines — whitespace, a doc comment — is cut at each newline.
     *
     * @param string $text
     * @return list<Node>
     */
    #[BareArray('pieces, grouped into lines by highlight()')]
    private static function php(string $text): array
    {
        $tokens = PhpToken::tokenize('<?php ' . $text);
        $nodes  = [];

        foreach ($tokens as $at => $token) {
            if ($token->is(T_OPEN_TAG)) {
                continue;
            }

            $kind = self::phpToken($tokens, $at);

            foreach (preg_split('/(\n)/', $token->text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
                $nodes[] = $kind === null || $part === self::NEWLINE ? new Text($part) : $kind->around($part);
            }
        }

        return $nodes;
    }

    /**
     * What one PHP token is, or null for plain text.
     *
     * Every keyword has a token of its own, and every name is `T_STRING`, so a name is told apart by
     * where it stands — see {@link self::name()}.
     *
     * @param list<PhpToken> $tokens
     * @param int            $at
     * @return CodeTag|null
     */
    #[BareArray('PhpToken::tokenize() returns a list, and this only reads it')]
    private static function phpToken(array $tokens, int $at): ?CodeTag
    {
        $token = $tokens[$at];

        return match (true) {
            $token->is([T_COMMENT, T_DOC_COMMENT])                              => CodeTag::Comment,
            $token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) => CodeTag::String,
            $token->is(T_VARIABLE)                                              => CodeTag::Variable,
            $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])              => CodeTag::Type,
            $token->is(T_STRING)                                                => self::name($tokens, $at),
            preg_match(self::WORD, $token->text) === 1                          => CodeTag::Keyword,
            default                                                             => null,
        };
    }

    /**
     * What a `T_STRING` is, by where it stands: after `new` a class, before `(` a call, and otherwise
     * a class when it is capitalised and a keyword — `string`, `self`, `void` — when it is not.
     *
     * @param list<PhpToken> $tokens
     * @param int            $at
     * @return CodeTag
     */
    #[BareArray('PhpToken::tokenize() returns a list, and this only reads it')]
    private static function name(array $tokens, int $at): CodeTag
    {
        return match (true) {
            self::neighbour($tokens, $at, -1)?->is(T_NEW) === true => CodeTag::Type,
            self::neighbour($tokens, $at, 1)?->text === '('        => CodeTag::Call,
            ctype_upper($tokens[$at]->text[0])                     => CodeTag::Type,
            default                                                => CodeTag::Keyword,
        };
    }

    /**
     * The nearest token that is not whitespace, before ($step -1) or after ($step 1).
     *
     * @param list<PhpToken> $tokens
     * @param int            $at
     * @param int            $step
     * @return PhpToken|null
     */
    #[BareArray('PhpToken::tokenize() returns a list, and this only reads it')]
    private static function neighbour(array $tokens, int $at, int $step): ?PhpToken
    {
        for ($i = $at + $step; isset($tokens[$i]); $i += $step) {
            if (!$tokens[$i]->is(T_WHITESPACE)) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * $text a line at a time, each line through $line, with a newline between them.
     *
     * @param string                       $text
     * @param callable(string): list<Node> $line
     * @return list<Node>
     */
    #[BareArray('pieces, grouped into lines by highlight()')]
    private static function lines(string $text, callable $line): array
    {
        $nodes = [];

        foreach (explode(self::NEWLINE, $text) as $number => $each) {
            if ($number > 0) {
                $nodes[] = new Text(self::NEWLINE);
            }

            array_push($nodes, ...$line($each));
        }

        return $nodes;
    }

    /**
     * One shell line: the command, its flags, the rest as it is, and a comment to the end.
     *
     * @param string $line
     * @return list<Node>
     */
    #[BareArray('pieces, grouped into lines by highlight()')]
    private static function shellLine(string $line): array
    {
        $comment = null;

        if (preg_match(self::SHELL_COMMENT, $line, $match, PREG_OFFSET_CAPTURE) === 1) {
            $comment = CodeTag::Comment->around($match[0][0]);
            $line    = substr($line, 0, $match[0][1]);
        }

        $nodes   = [];
        $command = true;

        foreach (preg_split('/( +)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
            if (trim($piece) === '') {
                $nodes[] = new Text($piece);
            } elseif ($command) {
                $nodes[] = CodeTag::Command->around($piece);
                $command = false;
            } elseif (str_starts_with($piece, '-')) {
                $nodes[] = CodeTag::Flag->around($piece);
            } else {
                $nodes[] = new Text($piece);
            }
        }

        if ($comment !== null) {
            $nodes[] = $comment;
        }

        return $nodes;
    }

    /**
     * One tree line: the branches drawn in front of it, the entry, and the note after the gap.
     *
     * @param string $line
     * @return list<Node>
     */
    #[BareArray('pieces, grouped into lines by highlight()')]
    private static function treeLine(string $line): array
    {
        preg_match(self::TREE_LINE, $line, $match);

        [, $branches, $entry] = $match;
        $gap                  = $match[3] ?? '';
        $note                 = $match[4] ?? '';
        $nodes                = [];

        if ($branches !== '') {
            $nodes[] = CodeTag::Branch->around($branches);
        }

        if ($entry !== '') {
            $nodes[] = new Text($entry);
        }

        if ($note !== '') {
            $nodes[] = new Text($gap);
            $nodes[] = CodeTag::Comment->around($note);
        }

        return $nodes;
    }
}
