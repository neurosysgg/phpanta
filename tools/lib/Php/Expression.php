<?php

declare(strict_types=1);

namespace Phpanta\Tool\Php;

/**
 * The Expression interface. One piece of the PHP source a site's entry writer emits.
 *
 * This exists for the reason the markup tree exists, and the objection is the same one: nothing
 * should build PHP by concatenating it, any more than `View/` builds HTML by concatenating it. Built
 * from a heredoc with `%s` holes and a `sprintf` per fragment, a class name would be a string, an
 * enum case `'Status::' . $status->name`, and the only thing standing between a typo and a data
 * file that will not parse would be reading it carefully.
 *
 * The emitter composes values instead and one renderer writes the syntax, so `Status::Draft` comes
 * out of a real `Status`, and a name that does not exist cannot be written down.
 *
 * **It is the same protocol as {@link \Phpanta\View\Html\Node}, and deliberately not the same
 * type.** Both say: the first line carries no indent, every line after it is indented to where the
 * caller put this one, and a child is rendered one step deeper. The two differ only in how the
 * caller names that column — a `string` here, an `int` of two-space steps there — and either form
 * would serve either tree.
 *
 * Making them one type was considered and turned down. Nothing anywhere holds "either kind of
 * node", which is the test that already makes `Support\TypedItems` a trait rather than a base
 * class; and a shared parent would have to live under `src/`, which none of this may do for two
 * mechanical reasons — a deploy mirrors `src/` to the server, and `phpunit.xml.dist` names it as
 * coverage source. A supertype whose only second implementor is a tool would be shipped to a
 * server that never runs the tool.
 */
interface Expression
{
    /**
     * The expression as PHP source.
     *
     * The **first line carries no indent** — the caller has already placed it, at a column this
     * cannot know. Every line after it is prefixed with `$indent`, which is why nesting works by
     * handing children `$indent . self::STEP`.
     *
     * @param string $indent The column this expression starts at.
     * @return string
     */
    public function render(string $indent = ''): string;
}
