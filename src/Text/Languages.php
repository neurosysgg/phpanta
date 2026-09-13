<?php

declare(strict_types=1);

namespace Phpanta\Text;

use Phpanta\Exception\TranslationException;
use Phpanta\Http\AcceptedLanguages;
use Phpanta\Support\Collection;

/**
 * The Languages class. Which of the framework's languages an app is written in, and which one it
 * falls back to.
 *
 * {@link Language} is every language the framework can write — a new one is a case and its endonym
 * — and this is the subset one app offers, in its order. The two are kept apart because a site
 * written in English alone must not answer a German cookie with a page it half-translated, and a
 * site written in both must not have to say so anywhere but here.
 *
 * **The default is the first argument, and the only place the default is stated.** It is what a
 * visitor asking for neither gets, what a tie in `Accept-Language` resolves to, and what a
 * translation with no text for a language falls back to.
 */
final readonly class Languages
{
    /** @var Collection<Language> The default first, then the rest, in the order given. */
    private Collection $offered;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Language $default   What a visitor asking for none of these gets.
     * @param Language ...$others The rest, in the order a visitor is offered them.
     * @throws TranslationException if a language is listed twice.
     */
    public function __construct(Language $default, Language ...$others)
    {
        $seen = [];

        foreach ([$default, ...$others] as $language) {
            if (in_array($language, $seen, true)) {
                throw new TranslationException(sprintf(
                    "'%s' is offered twice. Each language is offered once, and the first is the default.",
                    $language->value,
                ));
            }

            $seen[] = $language;
        }

        $this->offered = new Collection(Language::class)->with(...$seen);
    }

    /**
     * @return Language
     */
    public function default(): Language
    {
        return $this->offered->first();
    }

    /**
     * Every language offered, the default first.
     *
     * @return Collection<Language>
     */
    public function offered(): Collection
    {
        return $this->offered;
    }

    /**
     * The offered language whose tag is $tag, or null — never one this app does not offer.
     *
     * The narrowing is the point: a `lang` cookie or a `/language/xx` address naming a language the
     * framework knows but this app does not write is the same answer as one naming nothing at all.
     *
     * @param string $tag
     * @return Language|null
     */
    public function tryFrom(string $tag): ?Language
    {
        return $this->offered->first(static fn(Language $language): bool => $language->value === $tag);
    }

    /**
     * What $accepted prefers among the offered languages, and the default on a tie or a miss.
     *
     * @param AcceptedLanguages $accepted
     * @return Language
     */
    public function preferredBy(AcceptedLanguages $accepted): Language
    {
        return $accepted->preferred(...$this->offered->toValues());
    }
}
