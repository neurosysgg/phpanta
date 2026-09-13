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
     */
    public function __construct(
        private Path         $pattern,
        private Closure      $factory,
        private MethodPolicy $methods = MethodPolicy::ReadOnly,
    ) {}

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
