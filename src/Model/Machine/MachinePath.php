<?php

declare(strict_types=1);

namespace Phpanta\Model\Machine;

use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\File;

/**
 * The MachinePath class. A place on the machine the admin's `machine` service may reach: resolved,
 * and under one of its roots.
 *
 * **The address says where, and this decides whether.** The path after an action arrives decoded
 * from `{subject:path}`, so its segments may hold anything a segment can — `%2e%2e` decoded is `..`.
 * Nothing here trusts that: a subject with an empty segment, a `.`, a `..` or a NUL in it is refused
 * before anything is resolved, and what is left is resolved with `realpath()` and kept only if it
 * lands under a root. A link that leads out of every root is refused for the same reason — where it
 * lands is what is asked, not how it was spelled.
 *
 * **Two ways to resolve, for the two things an action does.** {@link self::resolve()} follows every
 * link, and is what reading needs: the file a link points at is the file to show. {@link self::entry()}
 * follows the links on the way to the last segment and not the last segment itself, and is what
 * renaming and removing need: removing a link removes the link, never what it points at.
 *
 * **A path is written with forward slashes wherever it came from**, so a subject is the same shape on
 * every machine: `etc/hosts` here, `C:/Users` on Windows, where the drive is the first segment.
 */
final readonly class MachinePath
{
    /** The longest name a filesystem here takes, in bytes. */
    private const int MAX_NAME = 255;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string    $path Absolute, normalised, and under $root.
     * @param Directory $root The root it is under.
     */
    private function __construct(public string $path, private Directory $root) {}

    /**
     * Where $subject leads, every link followed — or null where that is not under a root, or not
     * there at all. No subject is the one root there is, and null where there are several: which one
     * is for the caller to list.
     *
     * @param MachineConfig $config
     * @param string|null   $subject The path after the action, as the router decoded it.
     * @return self|null
     */
    public static function resolve(MachineConfig $config, ?string $subject): ?self
    {
        if ($subject === null) {
            $roots = $config->roots();

            return $roots->toValues() === [$roots->first()] ? self::rooted($roots->first()) : null;
        }

        $real = self::spelled($subject) ? realpath(self::absolute($subject)) : false;

        return $real === false ? null : self::under($config, self::normalised($real));
    }

    /**
     * The entry $subject names, not followed if it is a link — or null where its directory is not
     * under a root, or it is not there. A root itself is not an entry: nothing may rename or remove
     * one.
     *
     * @param MachineConfig $config
     * @param string        $subject
     * @return self|null
     */
    public static function entry(MachineConfig $config, string $subject): ?self
    {
        $cut    = strrpos($subject, '/');
        $name   = $cut === false ? $subject : substr($subject, $cut + 1);
        $parent = $cut === false ? null : substr($subject, 0, $cut);

        if (!self::spelled($subject)) {
            return null;
        }

        $directory = $parent === null
            ? self::normalised(self::absolute(''))
            : realpath(self::absolute($parent));

        if ($directory === false) {
            return null;
        }

        $under = self::under($config, self::normalised($directory));
        $path  = $under?->child($name);

        return $path === null || $under->isRootOf($path) || !self::exists($path) ? null : new self($path, $under->root);
    }

    /**
     * Whether $name may be given to an entry: one segment, not a dot segment, no NUL, and short
     * enough for a filesystem — a name for a new directory, a kept file, or a rename.
     *
     * @param string $name
     * @return bool
     */
    public static function isName(string $name): bool
    {
        return $name !== ''
            && $name !== '.'
            && $name !== '..'
            && strlen($name) <= self::MAX_NAME
            && strpbrk($name, "/\\\0") === false;
    }

    /**
     * $path with forward slashes and no trailing one, but for the root itself.
     *
     * @param string $path
     * @return string
     */
    public static function normalised(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return strlen($path) > 1 ? rtrim($path, '/') : $path;
    }

    /**
     * This place as the admin addresses it — the subject after an action — or null for the
     * filesystem's own root, which is addressed by no subject at all.
     *
     * @return string|null
     */
    public function subject(): ?string
    {
        return self::subjectOf($this->path);
    }

    /**
     * How the admin addresses the absolute $path — the subject after an action — or null for the
     * filesystem's own root. Nothing is resolved: an entry a listing names is addressed as it is
     * named, and whether that leads anywhere is asked when it is followed.
     *
     * @param string $path
     * @return string|null
     */
    public static function subjectOf(string $path): ?string
    {
        $subject = ltrim(self::normalised($path), '/');

        return $subject === '' ? null : $subject;
    }

    /**
     * Its last segment, or the path itself where it is a root with none — `/`, or `C:`.
     *
     * @return string
     */
    public function name(): string
    {
        $name = basename($this->path);

        return $name === '' ? $this->path : $name;
    }

    /**
     * Whether this is the root it is under, which has no parent the service may reach.
     *
     * @return bool
     */
    public function isRoot(): bool
    {
        return $this->path === $this->root->path;
    }

    /**
     * The directory this is in, where that is still under the root — or null for the root.
     *
     * @return self|null
     */
    public function parent(): ?self
    {
        return $this->isRoot() ? null : new self(self::normalised(dirname($this->path)), $this->root);
    }

    /**
     * Every place from the root down to this one, this one last — what a page's breadcrumbs name.
     *
     * @return Collection<self>
     */
    public function trail(): Collection
    {
        $trail = new Collection(self::class)->with($this);
        $place = $this->parent();

        while ($place !== null) {
            $trail = new Collection(self::class)->with($place, ...$trail->toValues());
            $place = $place->parent();
        }

        return $trail;
    }

    /**
     * The path of $name in this directory. $name is one {@link self::isName()} accepts.
     *
     * @param string $name
     * @return string
     */
    public function child(string $name): string
    {
        return rtrim($this->path, '/') . '/' . $name;
    }

    /**
     * Whether this is a directory — through a link, if it is one.
     *
     * @return bool
     */
    public function isDirectory(): bool
    {
        return is_dir($this->path);
    }

    /**
     * This place as a directory.
     *
     * @return Directory
     */
    public function directory(): Directory
    {
        return new Directory($this->path);
    }

    /**
     * This place as a file.
     *
     * @return File
     */
    public function file(): File
    {
        return new File($this->path);
    }

    /**
     * The place a root is.
     *
     * @param Directory $root
     * @return self
     */
    public static function rooted(Directory $root): self
    {
        return new self($root->path, $root);
    }

    /**
     * Whether $path is this place's own root.
     *
     * @param string $path
     * @return bool
     */
    private function isRootOf(string $path): bool
    {
        return $path === $this->root->path;
    }

    /**
     * $real as a place, where it is under one of $config's roots.
     *
     * @param MachineConfig $config
     * @param string        $real
     * @return self|null
     */
    private static function under(MachineConfig $config, string $real): ?self
    {
        $root = $config->roots()->first(
            static fn(Directory $root): bool => $root->path === '/'
                || $real === $root->path
                || str_starts_with($real, $root->path . '/'),
        );

        return $root === null ? null : new self($real, $root);
    }

    /**
     * Whether $subject is spelled as a path may be: segments with something in each, no dot segment
     * and no NUL.
     *
     * @param string $subject
     * @return bool
     */
    private static function spelled(string $subject): bool
    {
        foreach (explode('/', $subject) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                return false;
            }
        }

        return true;
    }

    /**
     * $subject as an absolute path on this machine: under `/`, or — on Windows — whatever drive its
     * first segment names.
     *
     * @param string $subject
     * @return string
     */
    private static function absolute(string $subject): string
    {
        return self::onWindows() ? $subject : '/' . $subject;
    }

    /**
     * Whether this machine is a Windows one, whose paths open with a drive rather than a slash.
     *
     * @return bool
     */
    public static function onWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Whether there is anything at $path, a link whose target is gone included.
     *
     * @param string $path
     * @return bool
     */
    private static function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }
}
