<?php

declare(strict_types=1);

namespace Phpanta\Test;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every class under one source root, named the way that root's autoloader names it.
 *
 * The rule tests — the five habits, the results that may not be discarded, the boundary — each walk
 * a tree of classes, and a site that vendors the framework runs the same rules over its own tree. So
 * the walk takes the tree as an argument: a directory, and the namespace prefix its autoloader maps
 * to it. The mapping from a path to a class is that prefix and nothing else, so a file this cannot
 * name is one production could not have loaded either.
 */
final class SourceTree
{
    /**
     * Cache for {@link self::classes()}, keyed by directory: every rule test asks, PHPUnit builds a
     * fresh test instance per method, and the answer does not change mid-run.
     *
     * @var array<string, array<string, class-string>>
     */
    private static array $classes = [];

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $directory Absolute path of the root, without a trailing slash.
     * @param string $prefix    The namespace prefix its autoloader maps to it, with its trailing `\`.
     */
    public function __construct(
        public readonly string $directory,
        public readonly string $prefix,
    ) {
    }

    /**
     * The framework's own `src/`, under `Phpanta\`.
     *
     * @return self
     */
    public static function framework(): self
    {
        return new self(PHPANTA_ROOT . '/src', 'Phpanta\\');
    }

    /**
     * Every class, interface, enum and trait under the root, as `absolute path => class-string`,
     * sorted by path.
     *
     * @return array<string, class-string>
     */
    public function classes(): array
    {
        if (isset(self::$classes[$this->directory])) {
            return self::$classes[$this->directory];
        }

        $classes = [];

        foreach (self::phpFilesUnder($this->directory) as $path) {
            $relative = substr($path, strlen($this->directory . '/'), -strlen('.php'));
            $class    = $this->prefix . str_replace('/', '\\', $relative);

            if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
                /** @var class-string $class */
                $classes[$path] = $class;
            }
        }

        ksort($classes);

        return self::$classes[$this->directory] = $classes;
    }

    /**
     * Every PHP file under the root and any further directories named, as absolute paths, sorted —
     * for the rules that read the filesystem rather than the autoloader, and so can reach tooling
     * and tests too.
     *
     * @param string ...$beside Absolute paths of further directories; one that does not exist adds nothing.
     * @return list<string>
     */
    public function files(string ...$beside): array
    {
        $paths = [];

        foreach ([$this->directory, ...$beside] as $directory) {
            $paths = [...$paths, ...self::phpFilesUnder($directory)];
        }

        sort($paths);

        return $paths;
    }

    /**
     * @param string $directory
     * @return list<string>
     */
    private static function phpFilesUnder(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $paths = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }
}
