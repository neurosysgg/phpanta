<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\Support\BareArray;
use Phpanta\View\Html\Fragment;
use Phpanta\View\Html\Node;
use Phpanta\View\Html\Text;
use PhpToken;

/**
 * What a code sample is written in, and how it is highlighted: each piece a reader looks for set in
 * a {@link Token}'s span, everything else as plain text, and nothing added, dropped or moved.
 *
 * Small on purpose, because the samples are few and known. PHP is read by PHP's own tokenizer,
 * which already knows every keyword; a shell line and a tree line are simple enough for a pattern.
 * A sample that needed more — heredocs in a shell line, say — would be the moment to say so here.
 */
enum SampleLanguage
{
    case Php;
    case Shell;
    case Tree;

    /** A comment in a shell line: a `#` that opens the line or follows a space, to its end. */
    private const string SHELL_COMMENT = '/(?:^|(?<= ))#.*\z/';

    /** A tree line: its branches, its entry, and the note after a gap of two spaces or more. */
    private const string TREE_LINE = '/^([ ├└│─]*)(.*?)(?:( {2,})(.*))?\z/u';

    /** A word PHP spells a keyword with — `final`, `fn`, `__DIR__`. */
    private const string WORD = '/^[a-z_]+\z/i';

    /**
     * $text, highlighted as this language.
     *
     * Newlines and whitespace are always plain text, so the `<pre>` this goes into always has text
     * among its children — which is what keeps the tree from putting a span on a line of its own.
     *
     * @param string $text
     * @return Fragment
     */
    public function highlight(string $text): Fragment
    {
        return new Fragment(...match ($this) {
            self::Php   => self::php($text),
            self::Shell => self::lines($text, self::shellLine(...)),
            self::Tree  => self::lines($text, self::treeLine(...)),
        });
    }

    /**
     * PHP, token by token. The sample is tokenized behind an open tag, which is dropped again: the
     * open tag's token is `<?php ` with its space, so the rest is the sample exactly.
     *
     * @param string $text
     * @return list<Node>
     */
    #[BareArray('spread into a Fragment, whose constructor is a variadic')]
    private static function php(string $text): array
    {
        $tokens = PhpToken::tokenize('<?php ' . $text);
        $nodes  = [];

        foreach ($tokens as $at => $token) {
            if ($token->is(T_OPEN_TAG)) {
                continue;
            }

            $kind    = self::phpToken($tokens, $at);
            $nodes[] = $kind === null ? new Text($token->text) : $kind->span($token->text);
        }

        return $nodes;
    }

    /**
     * What one PHP token is, or null for plain text.
     *
     * Every keyword has a token of its own, and every name is `T_STRING`, so a name is told apart by
     * where it stands: after `new` it is a class, before `(` a call, and otherwise a class when it is
     * capitalised and a keyword — `string`, `self`, `void` — when it is not.
     *
     * @param list<PhpToken> $tokens
     * @param int            $at
     * @return Token|null
     */
    #[BareArray('PhpToken::tokenize() returns a list, and this only reads it')]
    private static function phpToken(array $tokens, int $at): ?Token
    {
        $token = $tokens[$at];

        return match (true) {
            $token->is([T_COMMENT, T_DOC_COMMENT])                          => Token::Comment,
            $token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) => Token::String,
            $token->is(T_VARIABLE)                                          => Token::Variable,
            $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])          => Token::Type,
            $token->is(T_STRING)                                            => self::name($tokens, $at),
            preg_match(self::WORD, $token->text) === 1                      => Token::Keyword,
            default                                                         => null,
        };
    }

    /**
     * What a `T_STRING` is, by where it stands.
     *
     * @param list<PhpToken> $tokens
     * @param int            $at
     * @return Token
     */
    #[BareArray('PhpToken::tokenize() returns a list, and this only reads it')]
    private static function name(array $tokens, int $at): Token
    {
        return match (true) {
            self::neighbour($tokens, $at, -1)?->is(T_NEW) === true => Token::Type,
            self::neighbour($tokens, $at, 1)?->text === '('        => Token::Call,
            ctype_upper($tokens[$at]->text[0])                     => Token::Type,
            default                                                => Token::Keyword,
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
     * $text a line at a time, each line through $line, with the newlines between them as text.
     *
     * @param string                          $text
     * @param callable(string): list<Node>    $line
     * @return list<Node>
     */
    #[BareArray('spread into a Fragment, whose constructor is a variadic')]
    private static function lines(string $text, callable $line): array
    {
        $nodes = [];

        foreach (explode("\n", $text) as $number => $each) {
            if ($number > 0) {
                $nodes[] = new Text("\n");
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
    #[BareArray('spread into a Fragment, whose constructor is a variadic')]
    private static function shellLine(string $line): array
    {
        $comment = null;

        if (preg_match(self::SHELL_COMMENT, $line, $match, PREG_OFFSET_CAPTURE) === 1) {
            $comment = Token::Comment->span($match[0][0]);
            $line    = substr($line, 0, $match[0][1]);
        }

        $nodes   = [];
        $command = true;

        foreach (preg_split('/( +)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
            if (trim($piece) === '') {
                $nodes[] = new Text($piece);
            } elseif ($command) {
                $nodes[] = Token::Command->span($piece);
                $command = false;
            } elseif (str_starts_with($piece, '-')) {
                $nodes[] = Token::Flag->span($piece);
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
    #[BareArray('spread into a Fragment, whose constructor is a variadic')]
    private static function treeLine(string $line): array
    {
        preg_match(self::TREE_LINE, $line, $match);

        [, $branches, $entry] = $match;
        $gap                  = $match[3] ?? '';
        $note                 = $match[4] ?? '';
        $nodes                = [];

        if ($branches !== '') {
            $nodes[] = Token::Branch->span($branches);
        }

        if ($entry !== '') {
            $nodes[] = new Text($entry);
        }

        if ($note !== '') {
            $nodes[] = new Text($gap);
            $nodes[] = Token::Comment->span($note);
        }

        return $nodes;
    }
}
