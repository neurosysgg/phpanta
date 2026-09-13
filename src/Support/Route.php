<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Closure;
use NoDiscard;
use Phpanta\Controller\Controller;
use Phpanta\Controller\Layer;
use Phpanta\Http\Allow;
use Phpanta\Http\HttpMethod;

/**
 * A registered route — a {@link Path} paired with a factory that produces a Controller.
 *
 * Pattern syntax: static segments and `{param}` placeholders, e.g. `/posts/{slug}/{page}`, and a
 * placeholder may name its type — `{id:int}`, `{tag:slug}`; see {@link PlaceholderType}.
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
    public const string PLACEHOLDER_PATTERN = '/\{(\w+)(?::(\w+))?\}/';

    /** The compiled expression's delimiter, which {@link self::compile()} quotes in every static part. */
    private const string DELIMITER = '#';

    /**
     * The expression this route's pattern compiles to, built once, when the route is.
     *
     * It was built on every {@link self::matches()} call, which the router makes once per route
     * until one answers — the whole table compiled again for every request.
     */
    private string $regex;

    /**
     * The type of each placeholder, in the order they appear — what {@link self::matches()} decodes
     * each capture as.
     *
     * @var Collection<PlaceholderType>
     */
    private Collection $types;

    /**
     * @param Path $pattern
     * @param Closure $factory
     * @param MethodGate $methods   Which methods this route answers on. Nearly every route takes the
     *                              default, {@link MethodPolicy::ReadOnly}, and says nothing; a route
     *                              that also writes names its {@link MethodSet}; the API delegates.
     * @param Closure|null $exports Which pages a static export writes for this route: a closure
     *                              answering an iterable of placeholder values, each a string
     *                              (one placeholder) or a list of them. Only a site knows which
     *                              values exist, so a route with placeholders exports nothing
     *                              without one; `fn() => []` keeps a route without any out of an
     *                              export. See {@link self::exportedPaths()}.
     * @param Collection<Layer> $layers What stands around this route's controller, outermost first;
     *                              usually written with {@link self::through()} rather than here.
     */
    public function __construct(
        private Path         $pattern,
        private Closure      $factory,
        private MethodGate   $methods = MethodPolicy::ReadOnly,
        private ?Closure     $exports = null,
        private Collection   $layers = new Collection(Layer::class),
    ) {
        $this->types = self::types($pattern->value);
        $this->regex = self::compile($pattern->value, $this->types);
    }

    /**
     * The methods this route's refusal names — its gate's `Allow`.
     *
     * @return Allow
     */
    public function allowed(): Allow
    {
        return $this->methods->allow();
    }

    /**
     * This route with $layers around its controller, after any it already has.
     *
     * They run once the route has matched and its method gate has let the request through, so a
     * layer here only ever sees requests this route answers. `->through(new AdminGate())` is what
     * puts a page behind the admin password. See {@link Layer}.
     *
     * @param Layer ...$layers
     * @return static
     */
    #[NoDiscard('through() copies rather than adds, so a call whose result goes nowhere guards nothing')]
    public function through(Layer ...$layers): static
    {
        return new static(
            $this->pattern,
            $this->factory,
            $this->methods,
            $this->exports,
            $this->layers->with(...$layers),
        );
    }

    /**
     * What stands around this route's controller, outermost first.
     *
     * @return Collection<Layer>
     */
    public function layers(): Collection
    {
        return $this->layers;
    }

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
     * exist — the posts on a blog — only the site knows, so it says, with `$exports`;
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
     * **Each value comes back as its placeholder's type decodes it** — an `int` for `{id:int}`, and
     * otherwise the segment decoded — so this and {@link Path::to()} are inverses: `to()`
     * encodes a value into its segment — `a b` is linked as `a%20b` — and this hands back `a b`
     * rather than the encoding. It decodes after matching and never before, which is what keeps a
     * segment one segment: an encoded `%2F` is matched as part of the segment it arrived in, and
     * only then becomes a slash inside the value. A value is a key a controller looks something up
     * by — every one here finds it in a collection first — never a path it builds.
     *
     * @param string $path
     * @return array<int,string|int>|false Positional capture values on match, false otherwise.
     */
    #[BareArray(
        "preg_match's \$matches, by reference and shaped by the engine. This is the door, and "
        . 'the false beside it is why it cannot be a collection anyway: no-match and matched-'
        . 'nothing are different answers here.',
    )]
    public function matches(string $path): array|false
    {
        if (preg_match($this->regex, $path, $captures) !== 1) {
            return false;
        }

        array_shift($captures);

        $types  = $this->types->toValues();
        $values = [];

        foreach ($captures as $index => $capture) {
            $values[] = $types[$index]->decode($capture);
        }

        return $values;
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

    /**
     * The type of each placeholder in $pattern, in order — refused where the route is built for a
     * type that does not exist, rather than matched as something it was not meant to be.
     *
     * @param string $pattern
     * @return Collection<PlaceholderType>
     */
    private static function types(string $pattern): Collection
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $pattern, $placeholders, PREG_SET_ORDER);

        $types = new Collection(PlaceholderType::class);

        foreach ($placeholders as $placeholder) {
            $types = $types->with(PlaceholderType::named($placeholder[2] ?? ''));
        }

        return $types;
    }

    /**
     * $pattern as the expression that matches it.
     *
     * Every static part is quoted, delimiter included, so a path holding a character a regex
     * reads — a `.` in `/feed.xml`, a `+` — matches itself and nothing else, and none can end the
     * expression early. Each placeholder becomes a capture of its type's
     * {@link PlaceholderType::pattern()}.
     *
     * `\z` rather than `$`: `$` also matches immediately before a trailing newline, so `$` would let
     * `/posts/hello\n` match and capture the newline into the slug. The anchor that means "the
     * end" should be the one that says so.
     *
     * @param string                      $pattern
     * @param Collection<PlaceholderType> $types   The placeholders' types, in order.
     * @return string
     */
    private static function compile(string $pattern, Collection $types): string
    {
        $types = $types->toValues();
        $regex = '';

        // No null check, the way Path::to() has none: the pattern is a constant, and splitting a
        // string on a literal expression has no failure to report.
        foreach (preg_split(self::PLACEHOLDER_PATTERN, $pattern) as $index => $part) {
            $capture = $index === 0 ? '' : '(' . $types[$index - 1]->pattern() . ')';
            $regex  .= $capture . preg_quote($part, self::DELIMITER);
        }

        return self::DELIMITER . '\A' . $regex . '\z' . self::DELIMITER;
    }
}
