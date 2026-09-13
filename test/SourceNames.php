<?php

declare(strict_types=1);

namespace Phpanta\Test;

use PhpToken;
use ReflectionClass;

/**
 * What one PHP file names — the classes its code reaches and the targets its `{@link}`s point at —
 * resolved the way PHP resolves the same names.
 *
 * The boundary is drawn with this: the framework's suite asks it which classes the framework's code
 * writes and where its links land, and a site that vendors the framework asks it the same question
 * of the same files from its side. It reads tokens rather than grepping `use` lines, because a class
 * can be reached by an import, a qualified name, or an unqualified one PHP resolves against the
 * file's own namespace, and only the last never appears in an import.
 *
 * **Comments do not count as code.** A docblock that mentions a class is a sentence, not a
 * dependency; the tokenizer hands comments over as their own tokens, so they never reach
 * {@link self::classes()}. **A `{@link}` does count**, in {@link self::links()}: it is a reference an
 * editor follows and a reader trusts.
 */
final class SourceNames
{
    /**
     * Every class, interface, enum and trait one file names in code, resolved the way PHP resolves
     * it, sorted.
     *
     * A name is resolved against the file's imports first, then against its own namespace, and it
     * counts only if a class, interface, enum or trait of exactly that name exists — so a method
     * called `route()` is not the class `Route`, which `class_exists()` alone would say it was,
     * since it compares without case.
     *
     * @param string $path
     * @return list<class-string>
     */
    public static function classes(string $path): array
    {
        $tokens    = PhpToken::tokenize(file_get_contents($path));
        $namespace = '';
        $imports   = [];
        $names     = [];
        $count     = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->id === T_NAMESPACE && isset($tokens[$i + 2]) && $tokens[$i + 2]->id !== T_NS_SEPARATOR) {
                $namespace = $tokens[$i + 2]->text;
                $i        += 2;
                continue;
            }

            if ($token->id === T_USE && self::isImport($tokens, $i)) {
                [$name, $alias, $i]                        = self::import($tokens, $i);
                $imports[$alias ?? self::shortName($name)] = $name;
                $names[]                                   = $name;
                continue;
            }

            if ($token->id === T_NAME_FULLY_QUALIFIED) {
                $names[] = ltrim($token->text, '\\');
                continue;
            }

            if ($token->id !== T_STRING && $token->id !== T_NAME_QUALIFIED) {
                continue;
            }

            if (self::isMember($tokens, $i)) {
                continue;
            }

            $first   = strtok($token->text, '\\');
            $rest    = substr($token->text, strlen($first));
            $names[] = isset($imports[$first])
                ? $imports[$first] . $rest
                : ltrim($namespace . '\\' . $token->text, '\\');
        }

        $found = [];

        foreach (array_unique($names) as $name) {
            if (self::exists($name) && new ReflectionClass($name)->getName() === $name) {
                $found[] = $name;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * One file's `{@link}` targets, with what resolving them needs: its namespace, its imports as
     * `alias => name`, and the class it declares, which `self` and `static` mean.
     *
     * @param string $path
     * @return array{list<string>, string, array<string, string>, ?string}
     */
    public static function links(string $path): array
    {
        $tokens    = PhpToken::tokenize(file_get_contents($path));
        $namespace = '';
        $imports   = [];
        $self      = null;
        $targets   = [];
        $count     = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is([T_DOC_COMMENT, T_COMMENT])) {
                // A link can wrap, and then its target opens the next line, after the docblock's `*`.
                preg_match_all('/\{@link\s+(?:\*\s+)?([^\s}]+)/', $token->text, $found);
                $targets = [...$targets, ...$found[1]];
                continue;
            }

            if ($token->id === T_NAMESPACE && isset($tokens[$i + 2])) {
                $namespace = $tokens[$i + 2]->text;
                continue;
            }

            if ($token->id === T_USE && self::isImport($tokens, $i)) {
                [$name, $alias, $i]                        = self::import($tokens, $i);
                $imports[$alias ?? self::shortName($name)] = $name;
                continue;
            }

            if ($self === null && $token->is([T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT]) && !self::isMember($tokens, $i)) {
                $name = $tokens[$i + 2] ?? null;
                $self = $name?->id === T_STRING ? ltrim($namespace . '\\' . $name->text, '\\') : null;
            }
        }

        return [$targets, $namespace, $imports, $self];
    }

    /**
     * Whether a class, interface, enum or trait of that name exists.
     *
     * @param string $name
     * @return bool
     */
    public static function exists(string $name): bool
    {
        return class_exists($name) || interface_exists($name) || enum_exists($name) || trait_exists($name);
    }

    /**
     * Whether the `use` at $i is an import, rather than a trait's or a closure's: an import sits at
     * brace depth zero.
     *
     * @param list<PhpToken> $tokens
     * @param int            $i
     * @return bool
     */
    private static function isImport(array $tokens, int $i): bool
    {
        $depth = 0;

        for ($k = 0; $k < $i; $k++) {
            $depth += (int) ($tokens[$k]->text === '{') - (int) ($tokens[$k]->text === '}');
            $depth += (int) ($tokens[$k]->id === T_CURLY_OPEN || $tokens[$k]->id === T_DOLLAR_OPEN_CURLY_BRACES);
        }

        return $depth === 0;
    }

    /**
     * One import statement starting at $i, as `[name, alias, index of its ;]`.
     *
     * @param list<PhpToken> $tokens
     * @param int            $i
     * @return array{string, ?string, int}
     */
    private static function import(array $tokens, int $i): array
    {
        $name  = '';
        $alias = null;
        $count = count($tokens);

        for ($j = $i + 1; $j < $count && $tokens[$j]->text !== ';'; $j++) {
            if ($tokens[$j]->id === T_AS) {
                $alias = '';
                continue;
            }

            if ($tokens[$j]->id === T_STRING || $tokens[$j]->id === T_NAME_QUALIFIED) {
                if ($alias === null) {
                    $name .= $tokens[$j]->text;
                } else {
                    $alias = $tokens[$j]->text;
                }
            }
        }

        return [$name, $alias, $j];
    }

    /**
     * Whether the name at $i is a member, a declaration or a label rather than a class.
     *
     * `->name`, `?->name`, `::NAME`, `function name`, `const NAME`, `case Name`, a named argument's
     * `name:` and the declared name after `class`/`enum`/`interface`/`trait` are all spelled with
     * the same token as a class name; the token before (or, for a named argument, after) is what
     * tells them apart.
     *
     * @param list<PhpToken> $tokens
     * @param int            $i
     * @return bool
     */
    private static function isMember(array $tokens, int $i): bool
    {
        for ($p = $i - 1; $p >= 0 && $tokens[$p]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]); $p--);
        for ($n = $i + 1; $n < count($tokens) && $tokens[$n]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]); $n++);

        $before = $tokens[$p] ?? null;
        $after  = $tokens[$n] ?? null;

        if (
            $before !== null && $before->is([
            T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_CASE,
            T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT, T_NAMESPACE, T_GOTO,
            ])
        ) {
            return true;
        }

        // A named argument, `name: value` — but not the `?:` or `? :` of a ternary, whose colon
        // follows an expression rather than a bare name inside an argument list.
        return $after?->text === ':' && $before !== null && ($before->text === '(' || $before->text === ',');
    }

    /**
     * The last segment of a qualified name, or the name itself when it has none.
     *
     * @param string $name
     * @return string
     */
    private static function shortName(string $name): string
    {
        $separator = strrpos($name, chr(92));

        return $separator === false ? $name : substr($name, $separator + 1);
    }
}
