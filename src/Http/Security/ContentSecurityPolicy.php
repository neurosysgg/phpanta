<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

use NoDiscard;
use Phpanta\Exception\SecurityPolicyException;
use Phpanta\Http\HeaderValue;
use Phpanta\Support\BareArray;
use Phpanta\Support\SearchableCollection;

/**
 * The ContentSecurityPolicy class. A Content-Security-Policy assembled from typed parts.
 *
 * A policy is a set of {@link CspDirective}s, each mapped to {@link CspSource}s, and the header
 * text is generated rather than hand-written, so a misspelled directive or an unquoted `self`
 * cannot be written.
 *
 * Immutable: {@link self::allow()} returns a new instance, so a policy can be built up in a
 * readable chain without any step being able to mutate an earlier one.
 */
final readonly class ContentSecurityPolicy implements HeaderValue
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param SearchableCollection<CspSourceList> $directives Source lists keyed by directive
     *                        value. Normally left empty and built with {@link self::allow()}.
     */
    public function __construct(
        private SearchableCollection $directives = new SearchableCollection(CspSourceList::class),
    ) {}

    /**
     * Returns a copy of this policy with $directive allowed to load from $sources.
     *
     * @param CspDirective $directive The directive to set.
     * @param CspSource    ...$sources At least one. Use {@link CspKeyword::None} to allow nothing.
     * @return self
     * @throws SecurityPolicyException if $sources is empty, or $directive is already set.
     */
    #[NoDiscard('allow() returns a copy carrying the directive; a discarded one never reaches the header')]
    public function allow(CspDirective $directive, CspSource ...$sources): self
    {
        if ($sources === []) {
            throw new SecurityPolicyException(sprintf(
                "CSP directive '%s' needs at least one source; use CspKeyword::None to allow nothing.",
                $directive->value,
            ));
        }

        if ($this->directives->find($directive->value) !== null) {
            throw new SecurityPolicyException(sprintf(
                "CSP directive '%s' is already set. A browser honours the first occurrence and "
                . 'ignores the rest, so the second one would silently do nothing.',
                $directive->value,
            ));
        }

        return new self($this->directives->with(
            $directive->value,
            new CspSourceList()->with(...$sources),
        ));
    }

    /**
     * Returns the header value: `default-src 'self'; script-src 'self'; …`.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->directives
            ->map(static fn(CspSourceList $sources, string $directive): string => $directive . ' ' . $sources
                ->map(static fn(CspSource $source): string => $source->source())
                ->join(' '))
            ->join('; ');
    }

    /**
     * Returns every host this policy names, for anything that needs to reason about the
     * origins the page may reach — the test suite asserts no unexpected one creeps in.
     *
     * @return list<string>
     */
    #[BareArray(
        'a door, and one only the tests walk through: this exists so a suite can assert which '
        . 'origins a policy names, and it does so against a plain list.',
    )]
    public function hosts(): array
    {
        $hosts = new CspSourceList();

        foreach ($this->directives as $sources) {
            $hosts = $hosts->with(...$sources->where(
                static fn(CspSource $source): bool => $source instanceof CspHost,
            )->toValues());
        }

        // CspHost::source() is its origin, which is what makes this a map() rather than a loop
        // reaching past the interface for a property only one implementation has.
        return $hosts
            ->map(static fn(CspSource $source): string => $source->source())
            ->unique()
            ->toValues();
    }
}
