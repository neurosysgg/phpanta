<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\View\Html\Element;
use Phpanta\View\Html\TagName;

/**
 * The code samples' elements: a block, its lines, and the tokens a reader looks for in them.
 *
 * Named elements rather than spans with classes, so the markup says what each piece is and the
 * stylesheet reads like the structure — `code-block[language="php"] code-keyword`. Each tag is
 * registered client-side (`assets/ts/elements/code/`), which guards where it may stand, and
 * `assets/ts/model/CodeTag.ts` mirrors this list; `ContractTest` compares the two.
 */
enum CodeTag: string implements TagName
{
    /** A whole sample, naming its language in `language` — see {@link SampleLanguage}. */
    case Block = 'code-block';

    /** One line of it. */
    case Line = 'code-line';

    case Keyword  = 'code-keyword';
    case Type     = 'code-type';
    case Call     = 'code-call';
    case Variable = 'code-variable';
    case String   = 'code-string';
    case Comment  = 'code-comment';
    case Command  = 'code-command';
    case Flag     = 'code-flag';
    case Branch   = 'code-branch';

    /**
     * $text, marked as this token.
     *
     * @param string $text
     * @return Element
     */
    public function around(string $text): Element
    {
        return new Element($this)->containing($text);
    }

    /**
     * @return string
     */
    public function tagName(): string
    {
        return $this->value;
    }

    /**
     * Never: a custom element always has a closing tag.
     *
     * @return bool
     */
    public function isVoid(): bool
    {
        return false;
    }
}
