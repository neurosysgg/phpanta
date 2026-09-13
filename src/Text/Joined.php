<?php

declare(strict_types=1);

namespace Phpanta\Text;

use Phpanta\Support\Collection;

/**
 * The Joined class. Several texts as one, each put into the language before they are joined.
 *
 * For the one place a translatable has to be a single value rather than several children: a
 * `<title>` is `section — neuro.SYS`, and an attribute holds one string. Where the texts are
 * content, an element takes them as separate children instead, and nothing needs joining.
 */
final readonly class Joined implements Translatable
{
    /** @var Collection<Translatable> */
    private Collection $parts;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string       $glue     What goes between the parts, the same in every language.
     * @param Translatable ...$parts
     */
    public function __construct(private string $glue, Translatable ...$parts)
    {
        $this->parts = new Collection(Translatable::class)->with(...$parts);
    }

    /**
     * @param Language $language
     * @return string
     */
    public function in(Language $language): string
    {
        return $this->parts->map(static fn(Translatable $part): string => $part->in($language))->join($this->glue);
    }
}
