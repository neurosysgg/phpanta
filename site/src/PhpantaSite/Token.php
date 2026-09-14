<?php

declare(strict_types=1);

namespace PhpantaSite;

use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;

/**
 * What a piece of a code sample is, as the class its span carries — see {@link SampleLanguage}.
 *
 * Short on purpose: a sample is dozens of spans, and only the stylesheet reads the names.
 */
enum Token: string
{
    case Keyword  = 'kw';
    case Type     = 'ty';
    case Call     = 'fn';
    case Variable = 'var';
    case String   = 'str';
    case Comment  = 'com';
    case Command  = 'cmd';
    case Flag     = 'flag';
    case Branch   = 'tree';

    /**
     * $text, marked as this kind of token.
     *
     * @param string $text
     * @return Element
     */
    public function span(string $text): Element
    {
        return new Element(HtmlTag::Span)->attr(HtmlAttribute::ClassName, $this)->containing($text);
    }
}
