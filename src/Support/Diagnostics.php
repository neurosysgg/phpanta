<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Closure;

/**
 * The Diagnostics class. Runs an operation with PHP's own complaints handled rather than printed.
 *
 * This is what `@` does, said out loud and scoped. The calls it
 * stands in front of are all the same shape: a filesystem or crypto call whose failure is expected
 * and answered by its return value, next to a warning that must not reach the page —
 * {@link File::read()}'s is the clearest, since the headers have gone out by the time
 * `file_get_contents()` complains and the warning prints ahead of the doctype.
 *
 * **Two things `@` cannot do, and they are the whole argument for the class.**
 *
 * - **It cannot say which diagnostics it meant.** `@` silences every one raised anywhere in the
 *   expression, at any severity, including from a call nested inside it and including a class
 *   nobody anticipated — an `E_DEPRECATED` arriving with a PHP upgrade is silenced by the same
 *   character that was written for a missing file. {@link self::MUTED} names the severities this
 *   handles, and the handler answers `false` for everything else, which hands it back to PHP
 *   exactly as though nothing were installed.
 * - **It cannot answer for one call.** `error_get_last()` is process-global and sticky: it reports
 *   the last diagnostic raised anywhere, so "did *this* call warn, and what did it say" is a
 *   question it cannot be asked. {@link self::watched()} answers it, which is what
 *   {@link \Phpanta\View\Html\MarkupParser} needs — it refuses a policy document on any parse
 *   error at all, and the errors are the message.
 *
 * The cost is **0.58 µs** a call over `@` — 1.147 µs against 0.566 µs for the bare closure,
 * measured with no Xdebug loaded; against the 3.4 µs a failing `file_get_contents()` takes to
 * fail, and the handful of these a request makes, it does not show up. {@link self::muted()}
 * builds no collection at all, so it pays for the handler and nothing else.
 *
 * Note what carries no `#[\NoDiscard]`, unlike most of this namespace: both members run somebody
 * else's operation and hand back what it answered, and three of its call sites discard that answer
 * on purpose — {@link File::write()} unlinks its temporary file on the way out
 * of a failure it is already reporting. A dropped result here is the caller's decision, not a bug.
 */
#[BareString(
    'string',
    "get_debug_type()'s spelling in a class-string's place, the same coincidence TypedItems is "
    . 'excused for one layer down. This is the collection of messages watched() hands back, and no '
    . 'vocabulary anywhere spells a scalar type name for it to have been read off.',
)]
final readonly class Diagnostics
{
    /**
     * The severities this handles, and by omission the ones it refuses to.
     *
     * Everything a failing builtin raises to say "that did not work", and nothing that says the
     * program is wrong. `E_USER_ERROR` is deliberately absent beside its two twins, and so is
     * `E_RECOVERABLE_ERROR`: those are not a return value being explained, and swallowing one
     * would be the indiscriminate half of `@` reintroduced under a better name.
     *
     * **Nor a deprecation, or its user twin.** One says that a call will stop working, not that this
     * one did not — it explains no return value — and it is exactly what the class docblock says `@`
     * swallows by accident when it arrives with a PHP upgrade. Handed back, it reaches the log.
     */
    private const int MUTED = E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param mixed              $result   Whatever the operation answered, untouched.
     * @param Collection<string> $reported What it complained about on the way, in order.
     */
    public function __construct(public mixed $result, public Collection $reported) {}

    /**
     * Runs $operation with its diagnostics dropped, and answers what it answered.
     *
     * The replacement for `@` at every call site that only ever wanted the return value. The
     * closure is the scope: a warning raised inside it is handled, and one raised a statement later
     * is not — which is the property `@` has only by accident of an expression being short.
     *
     * @param Closure $operation
     * @return mixed
     */
    public static function muted(Closure $operation): mixed
    {
        // A first-class callable rather than a closure around it: PHP hands a userland callback the
        // other three arguments harmlessly, and this one wants none of them.
        set_error_handler(self::handles(...));

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Runs $operation and keeps what it complained about beside what it answered.
     *
     * The half `@` has no version of. One caller today —
     * {@link \Phpanta\View\Html\MarkupParser::read()}, where `Dom\HTMLDocument` reports every
     * HTML5 tokenizer and tree error as a warning and then recovers silently, so the warnings are
     * the only evidence that a hand-edited legal document lost half of itself.
     *
     * The handler is restored before the collection is built, so a `TypeError` from the collection
     * cannot be raised while this class is still standing in front of PHP's own reporting.
     *
     * @param Closure $operation
     * @return self
     */
    public static function watched(Closure $operation): self
    {
        $reported = [];

        set_error_handler(static function (int $severity, string $message) use (&$reported): bool {
            $handled = self::handles($severity);

            if ($handled) {
                $reported[] = $message;
            }

            return $handled;
        });

        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return new self($result, new Collection('string')->with(...$reported));
    }

    /**
     * Whether this is a severity this class claims.
     *
     * The whole decision, in one place, because both members make it and a handler that disagreed
     * with its sibling about what "handled" means would be the subtlest possible version of the bug
     * this class exists to prevent.
     *
     * Answering `false` is what hands the diagnostic back to PHP untouched, which is the half `@`
     * has no version of — and it is why {@link self::MUTED} is a list of what *is* claimed rather
     * than a list of what is not.
     *
     * @param int $severity
     * @return bool
     */
    private static function handles(int $severity): bool
    {
        return ($severity & self::MUTED) !== 0;
    }
}
