<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Closure;
use NoDiscard;
use Phpanta\Controller\Controller;
use Phpanta\Http\HttpMethod;

/**
 * A registered route — a {@link Path} paired with a factory that produces a Controller.
 *
 * Pattern syntax: static segments and `{param}` placeholders, e.g. `/releases/{slug}/{format}`.
 * The pattern is a case rather than a string because the views build their links from the same
 * cases — see {@link Path}, which is where that argument is made.
 */
#[BareString(
    'string',
    'the declared type of the collection exportedPaths() answers: addresses, which are strings. The '
    . 'same scalar-in-a-class-string coincidence Vocabulary and TypedItems excuse.',
)]
readonly class Route
{
    /**
     * What a placeholder looks like, shared with {@link FillsPlaceholders::to()}.
     *
     * One constant because the two halves of the syntax have to agree: this class turns a
     * placeholder into a capture group and that method fills it in, and a pattern that only one of
     * them recognised would match a URL nothing links to, or link to a URL nothing matches.
     */
    public const string PLACEHOLDER_PATTERN = '/\{(\w+)\}/';

    /**
     * @param Path $pattern
     * @param Closure $factory
     * @param MethodPolicy $methods Who decides which methods this route answers on. Nine routes
     *                              take the default and say nothing; see that enum for the one
     *                              that does not, and why it cannot carry a method set instead.
     * @param Closure|null $exports Which pages a static export writes for this route: a closure
     *                              answering an iterable of placeholder values, each a string
     *                              (one placeholder) or a list of them. Only a site knows which
     *                              values exist, so a route with placeholders exports nothing
     *                              without one; `fn() => []` keeps a route without any out of an
     *                              export. See {@link self::exportedPaths()}.
     */
    public function __construct(
        private Path         $pattern,
        private Closure      $factory,
        private MethodPolicy $methods = MethodPolicy::ReadOnly,
        private ?Closure     $exports = null,
    ) {}

    /**
     * The address pattern this route answers on.
     *
     * @return Path
     */
    public function path(): Path
    {
        return $this->pattern;
    }

    /**
     * The addresses a static export writes a page for on this route's behalf.
     *
     * **A route is a page when it only reads and is one address**: without placeholders, its path
     * is the whole of it. A route with placeholders is one address per value, and which values
     * exist — the releases in a catalogue — only the site knows, so it says, with `$exports`;
     * without one it exports nothing rather than guessing. A route under any other
     * {@link MethodPolicy} is never a page — the API is the one today, and a static host has
     * nowhere to send a request it would have to verify.
     *
     * The addresses are filled by {@link Path::to()}, the same way a view builds a link to them, so
     * an exported page is at exactly the address the site's own links name.
     *
     * @return Collection<string>
     */
    public function exportedPaths(): Collection
    {
        $paths = new Collection('string');

        if ($this->methods !== MethodPolicy::ReadOnly) {
            return $paths;
        }

        if ($this->exports === null) {
            return preg_match(self::PLACEHOLDER_PATTERN, $this->pattern->value) === 1
                ? $paths
                : $paths->with($this->pattern->to());
        }

        foreach (($this->exports)() as $values) {
            $paths = $paths->with($this->pattern->to(...(is_array($values) ? $values : [$values])));
        }

        return $paths;
    }

    /**
     * Whether this route answers on $method.
     *
     * @param HttpMethod|null $method
     * @return bool
     */
    #[NoDiscard('this is the method gate\'s decision; dropping it lets the request through')]
    public function accepts(?HttpMethod $method): bool
    {
        return $this->methods->accepts($method);
    }

    /**
     * Tests whether this route matches $path.
     *
     * @param string $path
     * @return array<int,string>|false Positional capture values on match, false otherwise.
     */
    #[BareArray(
        "preg_match's \$matches, by reference and shaped by the engine. This is the door, and "
        . 'the false beside it is why it cannot be a collection anyway: no-match and matched-'
        . 'nothing are different answers here.',
    )]
    public function matches(string $path): array|false
    {
        // \z rather than $: `$` also matches immediately before a trailing newline, so `$` would
        // let `/releases/ill\n` match and capture the newline into the slug. Not reachable today —
        // parse_url() does not decode %0a and Apache refuses a raw one in the request line — but
        // the anchor that means "the end" should be the one that says so.
        $regex = '@^' . preg_replace(self::PLACEHOLDER_PATTERN, '([^/]+)', $this->pattern->value) . '\z@';
        if (!preg_match($regex, $path, $m)) {
            return false;
        }
        array_shift($m);
        return $m;
    }

    /**
     * Invokes the factory with the captured params and returns the resulting Controller.
     *
     * @param array $params
     * @return Controller
     */
    #[BareArray(
        'the far side of matches(): the captures go straight into the factory as a variadic, and '
        . 'what PHP checks them against is the controller constructor behind it.',
    )]
    public function createController(array $params): Controller
    {
        return ($this->factory)(...$params);
    }
}
