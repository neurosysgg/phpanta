<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Composer\Autoload\ClassLoader;
use Phpanta\Test\SourceNames;
use Phpanta\Test\SourceTree;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The framework's line: every class its code names is its own or PHP's, and every `{@link}` it
 * writes lands inside it.
 *
 * The framework cannot know about a site that uses it — a second site built on it would have none of
 * the first one's classes — so no framework file may reach one, whether by an import, a qualified
 * name, or an unqualified one that PHP resolves against the file's own namespace. The last is why
 * this reads tokens and resolves names through {@link SourceNames} rather than grepping `use` lines.
 *
 * The rule is stated from the framework's side, so it needs no site to state it: a name the code
 * writes that resolves to a class must resolve to a `Phpanta\` class or to one PHP itself declares.
 * **The framework has no runtime dependencies to allow**, so everything else fails — a site's class, which
 * exists whenever the suite runs inside a site that vendors the framework, and a composer package's,
 * which exists whenever composer does. Tooling and test code may also name what composer installs
 * for development — PHPUnit, php-code-coverage — since that is what they run on, and neither is
 * deployed.
 *
 * **Comments do not count as code; a `{@link}` does**, in its own test: it is a reference an editor
 * follows and a reader trusts, so every one has to land on the framework or on PHP itself. A link to
 * a site's class — unqualified, it would dangle in any other site — fails there, and so does one that
 * dangles already.
 */
#[CoversNothing]
final class BoundaryTest extends TestCase
{
    /**
     * Every class `src/` names in code is the framework's own or PHP's.
     *
     * @return void
     */
    public function testEveryClassTheFrameworkNamesIsItsOwnOrPhps(): void
    {
        $foreign = [];

        foreach (SourceTree::framework()->files() as $path) {
            foreach (SourceNames::classes($path) as $class) {
                if (!self::isOwnOrPhps($class)) {
                    $foreign[] = self::relative($path) . ' → ' . $class;
                }
            }
        }

        $this->assertSame([], $foreign, 'A framework file names a class that is neither its own nor PHP\'s.');
    }

    /**
     * Every class `tools/` and `test/` name in code is the framework's own, PHP's, or a development
     * dependency's.
     *
     * Neither tree is deployed, and both run where composer has installed what the framework is
     * developed with: the tests under PHPUnit, the coverage merge on top of php-code-coverage. What
     * they may not name is a site's class, which is no dependency of the framework's at all.
     *
     * @return void
     */
    public function testEveryClassTheFrameworksToolsAndTestsNameIsItsOwnPhpsOrADevelopmentDependencys(): void
    {
        $foreign = [];
        $vendor  = dirname((string) new ReflectionClass(ClassLoader::class)->getFileName(), 2) . '/';
        $tree    = new SourceTree(PHPANTA_ROOT . '/tools', 'Phpanta\\Tool\\');

        foreach ($tree->files(PHPANTA_ROOT . '/test') as $path) {
            foreach (SourceNames::classes($path) as $class) {
                $installed = str_starts_with((string) new ReflectionClass($class)->getFileName(), $vendor);

                if (!self::isOwnOrPhps($class) && !$installed) {
                    $foreign[] = self::relative($path) . ' → ' . $class;
                }
            }
        }

        $this->assertSame([], $foreign, 'A framework test names a class that is not its own, PHP\'s or composer\'s.');
    }

    /**
     * The framework's own tree is not empty — a walk that found nothing would pass the tests above.
     *
     * @return void
     */
    public function testTheFrameworkTreeIsWhereTheWalkLooks(): void
    {
        $this->assertGreaterThan(100, count(SourceTree::framework()->classes()));
    }

    /**
     * Every `{@link}` a framework file writes — in `src/`, `tools/` and `test/` — names a class, member
     * or function the framework or PHP itself has, resolved the way PHP resolves the same name in code.
     *
     * @return void
     */
    public function testEveryLinkInTheFrameworkLandsInsideIt(): void
    {
        $broken = [];
        $links  = 0;

        foreach (SourceTree::framework()->files(PHPANTA_ROOT . '/tools', PHPANTA_ROOT . '/test') as $path) {
            [$targets, $namespace, $imports, $self] = SourceNames::links($path);

            foreach ($targets as $target) {
                $links++;

                if (!self::landsInTheFramework($target, $namespace, $imports, $self)) {
                    $broken[] = self::relative($path) . ' → ' . $target;
                }
            }
        }

        $this->assertGreaterThan(500, $links, 'The walk found too few links to be reading the framework.');
        $this->assertSame([], $broken, 'A framework docblock links outside the framework, or to nothing.');
    }

    /**
     * Whether a class is the framework's, or one PHP itself declares.
     *
     * @param class-string $class
     * @return bool
     */
    private static function isOwnOrPhps(string $class): bool
    {
        return str_starts_with($class, 'Phpanta\\') || new ReflectionClass($class)->isInternal();
    }

    /**
     * Whether one link target names something in the framework or in PHP: a URL is taken as written,
     * `name()` is a function, and `Class::member` needs the member as well as the class.
     *
     * @param string                $target
     * @param string                $namespace
     * @param array<string, string> $imports
     * @param ?string               $self
     * @return bool
     */
    private static function landsInTheFramework(string $target, string $namespace, array $imports, ?string $self): bool
    {
        if (str_contains($target, '://')) {
            return true;
        }

        [$name, $member] = [...explode('::', $target, 2), null];

        if ($member === null && str_ends_with($name, '()')) {
            $function = ltrim(substr($name, 0, -2), '\\');

            return function_exists($function) || function_exists($namespace . '\\' . $function);
        }

        $class = self::resolved($name, $namespace, $imports, $self);

        if ($class === null) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        if (!self::isOwnOrPhps($class) || $reflection->getName() !== $class) {
            return false;
        }

        return match (true) {
            $member === null                => true,
            str_ends_with($member, '()')    => $reflection->hasMethod(substr($member, 0, -2)),
            str_starts_with($member, '$')   => $reflection->hasProperty(substr($member, 1)),
            default                         => $reflection->hasConstant($member)
                || $reflection->hasMethod($member) || $reflection->hasProperty($member),
        };
    }

    /**
     * The class a linked name means, or null when it means none: `self`, a fully qualified name, an
     * import, the file's own namespace — and, for a bare name that is none of those, a class PHP
     * itself declares, the way a docblock tool falls back to the global namespace.
     *
     * @param string                $name
     * @param string                $namespace
     * @param array<string, string> $imports
     * @param ?string               $self
     * @return ?string
     */
    private static function resolved(string $name, string $namespace, array $imports, ?string $self): ?string
    {
        $first = strtok($name, '\\');

        $candidate = match (true) {
            $name === 'self' || $name === 'static' => $self,
            str_starts_with($name, '\\')           => ltrim($name, '\\'),
            isset($imports[$first])                => $imports[$first] . substr($name, strlen($first)),
            default                                => ltrim($namespace . '\\' . $name, '\\'),
        };

        if ($candidate !== null && SourceNames::exists($candidate)) {
            return $candidate;
        }

        $global = !str_contains($name, '\\') && SourceNames::exists($name) && new ReflectionClass($name)->isInternal();

        return $global ? $name : null;
    }

    /**
     * A path as the framework's own directory sees it.
     *
     * @param string $path
     * @return string
     */
    private static function relative(string $path): string
    {
        return substr($path, strlen(PHPANTA_ROOT) + 1);
    }
}
