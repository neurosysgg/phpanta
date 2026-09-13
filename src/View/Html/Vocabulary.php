<?php

declare(strict_types=1);

namespace Phpanta\View\Html;

use BackedEnum;
use NoDiscard;
use Phpanta\Support\BareString;
use Phpanta\Support\Collection;

/**
 * The Vocabulary class. Every tag and attribute name hand-authored markup may be parsed into.
 *
 * {@link MarkupParser} reads a document back into the tree, and what it will accept is closed: an
 * element name has to be a {@link TagName} case and an attribute name an {@link AttributeName}
 * case, so an `onerror=` or a `<form>` that appears in a future re-export is refused when the file
 * loads rather than rendered unread. What every site can parse is {@link self::standard()} — the
 * browser's own tags and attributes, and the one attribute the framework's navigation reads. A site
 * adds its custom elements and their attributes, and {@link \Phpanta\App::vocabulary()} is where
 * it says so.
 *
 * **Listed rather than discovered by reflection**, so that the set is a decision somebody made —
 * and `HtmlTest` pins the site's against reflection in both directions, so an implementation nobody
 * adds is a failing test rather than an element that mysteriously will not parse. Order means
 * nothing: a name resolves through whichever enum spells it, and no two of them spell the same one.
 */
#[BareString(
    'string',
    'the declared type of the two collections this holds, whose items are enum class names; see '
    . 'TerminalCommand, which writes it for the same reason',
)]
final readonly class Vocabulary
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<string> $tags       The {@link TagName} enums, by class name.
     * @param Collection<string> $attributes The {@link AttributeName} enums, by class name.
     */
    private function __construct(private Collection $tags, private Collection $attributes) {}

    /**
     * What every site can parse: HTML's own tags and attributes, and `data-no-spa`, which the
     * framework's navigation reads.
     *
     * @return self
     */
    public static function standard(): self
    {
        return new self(
            new Collection('string')->with(HtmlTag::class),
            new Collection('string')->with(HtmlAttribute::class, LinkAttribute::class),
        );
    }

    /**
     * This vocabulary with $enums' tags as well.
     *
     * @param class-string<TagName&BackedEnum> ...$enums
     * @return self
     */
    #[NoDiscard('withTags() copies; the vocabulary it was called on is unchanged')]
    public function withTags(string ...$enums): self
    {
        return new self($this->tags->with(...$enums), $this->attributes);
    }

    /**
     * This vocabulary with $enums' attributes as well.
     *
     * @param class-string<AttributeName&BackedEnum> ...$enums
     * @return self
     */
    #[NoDiscard('withAttributes() copies; the vocabulary it was called on is unchanged')]
    public function withAttributes(string ...$enums): self
    {
        return new self($this->tags, $this->attributes->with(...$enums));
    }

    /**
     * The tag spelled $name, or null for a name this vocabulary does not have.
     *
     * @param string $name
     * @return TagName|null
     */
    public function tagNamed(string $name): ?TagName
    {
        foreach ($this->tags as $enum) {
            $case = $enum::tryFrom($name);

            if ($case !== null) {
                return $case;
            }
        }

        return null;
    }

    /**
     * The attribute spelled $name, or null for a name this vocabulary does not have.
     *
     * @param string $name
     * @return AttributeName|null
     */
    public function attributeNamed(string $name): ?AttributeName
    {
        foreach ($this->attributes as $enum) {
            $case = $enum::tryFrom($name);

            if ($case !== null) {
                return $case;
            }
        }

        return null;
    }

    /**
     * The tag enums, for a refusal to name and a test to pin.
     *
     * @return Collection<string>
     */
    public function tags(): Collection
    {
        return $this->tags;
    }

    /**
     * The attribute enums, for a refusal to name and a test to pin.
     *
     * @return Collection<string>
     */
    public function attributes(): Collection
    {
        return $this->attributes;
    }
}
