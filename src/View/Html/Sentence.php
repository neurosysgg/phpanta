<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

use Phpanta\Exception\ElementException;
use Phpanta\Exception\TranslationException;
use Phpanta\Support\SearchableCollection;
use Phpanta\Text\Language;
use Phpanta\Text\Translatable;

/**
 * The Sentence class. Translated text with nodes in it — a link, a piece of code — placed where each
 * language's word order puts them.
 *
 * **A sentence that holds a node cannot be a catalog case split around it.** English writes "read
 * the {link} first" and German "lies zuerst den {link}", and a view that wrote the sentence as three
 * pieces — text, the link, text — would put the link where English put it in every language. So the
 * text carries named placeholders and the view hands the nodes over by the same names:
 *
 * ```php
 * new Element(HtmlTag::P)->containing(new Sentence(
 *     Texts::Rules::Collections,
 *     code: new Element(ProseTag::Code)->containing('Collection'),
 * ));
 * ```
 *
 * **The grammar is its own, and it is small.** A placeholder is `{name}` — a lower-case letter, then
 * letters and digits. Everything else is literal, apostrophes included, so this is not ICU and not
 * for {@link \Phpanta\Text\Phrase}: `with()` would have consumed the braces before this saw them. A
 * brace that is no placeholder is refused rather than guessed at; a brace the prose needs goes inside
 * a part, where it is somebody's code and escaped as such.
 *
 * **Everything that could go quietly wrong is loud.** A part given by position has no name to be
 * placed by; a placeholder with no part would render as nothing; a part the text never names would be
 * dropped. Each is refused — the last two in the language being rendered, since a German text that
 * forgot a placeholder the English one has is exactly the mistake worth catching.
 *
 * The text runs are {@link Text}, so they are escaped like any other; the parts render as the nodes
 * they are, in the same language and at the same depth. A sentence is text as far as its parent is
 * concerned — see {@link Fragment::writesText()} — so the element around it stays on one line.
 */
final readonly class Sentence implements Node
{
    /** A placeholder: a brace, a name, a brace. The one piece of grammar the text has. */
    private const string PLACEHOLDER = '#\{([a-z][A-Za-z0-9]*)\}#';

    /** The parts, by the names the text's placeholders give them. */
    private SearchableCollection $parts;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Translatable $text The text, placeholders and all.
     * @param Node ...$parts Named, one per placeholder: `code: new Element(…)`.
     *
     * @throws ElementException if a part is given by position rather than by name.
     */
    public function __construct(private Translatable $text, Node ...$parts)
    {
        $named = new SearchableCollection(Node::class);

        foreach ($parts as $name => $part) {
            if (!is_string($name)) {
                throw new ElementException(sprintf(
                    'A sentence takes its parts by the names its placeholders give them; %s was given one by'
                    . ' position.',
                    TranslatedText::named($text),
                ));
            }

            $named = $named->with($name, $part);
        }

        $this->parts = $named;
    }

    /**
     * @param int $depth
     * @param Language|null $language
     * @return string
     *
     * @throws TranslationException if no language is in scope, if the text in that language holds a
     *                              brace that is no placeholder, names a part it was not given, or
     *                              leaves one it was given unnamed.
     */
    public function render(int $depth = 0, ?Language $language = null): string
    {
        $written  = TranslatedText::resolve($this->text, $language);
        $rendered = '';
        $placed   = [];

        // The captured names sit at the odd offsets, the text between them at the even ones.
        foreach ((array) preg_split(self::PLACEHOLDER, $written, -1, PREG_SPLIT_DELIM_CAPTURE) as $at => $piece) {
            if ($at % 2 === 0) {
                $rendered .= $this->run((string) $piece, $language);
                continue;
            }

            $part = $this->parts->find($piece) ?? throw new TranslationException(sprintf(
                '%s names {%s} in %s, and the sentence was given no part by that name.',
                TranslatedText::named($this->text),
                $piece,
                $language?->name,
            ));

            $placed[$piece] = true;
            $rendered      .= $part instanceof Fragment
                ? $part->renderInline($depth, $language)
                : $part->render($depth, $language);
        }

        foreach ($this->parts as $name => $part) {
            if (!isset($placed[$name])) {
                throw new TranslationException(sprintf(
                    '%s was given the part %s, which it never names in %s.',
                    TranslatedText::named($this->text),
                    $name,
                    $language?->name,
                ));
            }
        }

        return $rendered;
    }

    /**
     * A run of text between placeholders, escaped — or refused, if a brace is left in it.
     *
     * @param string $run
     * @param Language|null $language
     * @return string
     *
     * @throws TranslationException
     */
    private function run(string $run, ?Language $language): string
    {
        if (strpbrk($run, '{}') !== false) {
            throw new TranslationException(sprintf(
                "%s holds a brace that is no placeholder in %s: '%s'. A placeholder is {name}; a brace the"
                . ' text needs goes inside a part.',
                TranslatedText::named($this->text),
                $language?->name,
                $run,
            ));
        }

        return new Text($run)->render();
    }
}
