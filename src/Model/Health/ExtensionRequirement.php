<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

use Closure;
use Phpanta\Exception\RequirementException;
use Phpanta\Exception\SiteException;

/**
 * The ExtensionRequirement class. An extension an installation needs, and optionally the proof that
 * it works.
 *
 * **Registered and working are two questions**, and this answers whichever it is given.
 * Without a proof it asks `extension_loaded()`, which is the first question only — whether the name
 * is registered. With one it runs the proof, which should use what the installation actually uses:
 * the class it constructs, the function it calls. `::class` on an extension's own class resolves
 * lexically, so naming one costs nothing on a runtime that lacks it.
 *
 * ```php
 * new ExtensionRequirement('com_dotnet', Level::Optional, static fn(): bool => class_exists(\COM::class))
 * ```
 *
 * **The found column says which question failed**, because the difference is the whole diagnostic:
 * an extension that is not there is a hosting ticket, and one that is registered but fails its
 * proof is a build of it missing the part this installation reaches for.
 *
 * The proof runs at {@link self::check()} and nowhere else, so declaring the requirement on every
 * request costs nothing but the closure.
 */
final readonly class ExtensionRequirement implements Requirement
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $name The extension's name, as `extension_loaded()` and `phpversion()` spell it.
     * @param Level $level
     * @param Closure(): bool|null $proof Whether the extension does what this installation needs,
     *                                    asked by doing it. Null to settle for its being registered.
     *                                    One of the framework's own exceptions thrown from it is
     *                                    read as the proof failing; anything else is a bug in it.
     *
     * @throws RequirementException if $name is empty.
     */
    public function __construct(
        private string $name,
        private Level $level = Level::Required,
        private ?Closure $proof = null,
    ) {
        if ($name === '') {
            throw new RequirementException('An extension requirement needs the name of an extension.');
        }
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return Area
     */
    public function area(): Area
    {
        return Area::Extensions;
    }

    /**
     * @return Level
     */
    public function level(): Level
    {
        return $this->level;
    }

    /**
     * @return string
     */
    public function expected(): string
    {
        return $this->proof === null ? 'registered' : 'working';
    }

    /**
     * @return Finding
     */
    public function check(): Finding
    {
        $registered = extension_loaded($this->name);

        // A check never throws: nothing catches it, so one throw is a 500 for the whole report. A
        // proof that fails in one of the framework's own ways has failed, and says how.
        try {
            $met = $this->proof === null ? $registered : ($this->proof)() === true;
        } catch (SiteException $thrown) {
            return new Finding(
                sprintf(
                    '%s, but its proof threw: %s',
                    $registered ? 'registered' : 'not registered',
                    $thrown->getMessage(),
                ),
                false,
            );
        }

        return new Finding(match (true) {
            $met && $registered => (string) phpversion($this->name),
            $met                => 'not registered, but its proof holds',
            $registered         => 'registered, but its proof fails',
            default             => 'not registered',
        }, $met);
    }
}
