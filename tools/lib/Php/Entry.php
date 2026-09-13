<?php

declare(strict_types=1);

namespace Phpanta\Tool\Php;

/**
 * The Entry class. One `'slug' => new Post(…),` line of a data file of entries, and its imports.
 *
 * The outermost node, and the only one that places its own indent: everything below it is rendered
 * relative to wherever its parent put it, which is what lets the same `Call` be a top-level entry
 * or an argument three levels down.
 */
final readonly class Entry
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $key   The array key — the entry's slug.
     * @param Call   $value
     */
    public function __construct(private string $key, private Call $value) {}

    /**
     * @param string $indent
     * @return string
     */
    public function render(string $indent = Call::STEP): string
    {
        return $indent . var_export($this->key, true) . ' => ' . $this->value->render($indent) . ',';
    }

    /**
     * Every class the entry names, so the author can be told what the data file has to import.
     *
     * Worth having because the entry writes short names: `Author::named(…)` is a parse error in a
     * file that never imported `Author`, and a class new enough that no existing entry uses it is
     * one no existing entry imports either.
     *
     * @return list<string>
     */
    public function imports(): array
    {
        $names = $this->value->classNames();

        sort($names);

        return $names;
    }
}
