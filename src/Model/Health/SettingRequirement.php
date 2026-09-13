<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

use Phpanta\Exception\RequirementException;

/**
 * The SettingRequirement class. A php.ini directive, and what its value has to be.
 *
 * **A directive the engine has never heard of is a failure, loudly.** `ini_get()` answers `false`
 * for a name that does not exist, and a report that cast that to `''` would print a misspelled
 * `memory_limmit` as a directive that is merely unset — the silent failure {@link PhpSetting} was
 * written against, one layer down. Here it is a `fail` line naming the fault, whether the
 * misspelling is ours or the directive belongs to an extension this host does not have.
 *
 * The directive is a string rather than a {@link PhpSetting}, because the vocabulary of directives
 * is the engine's and every extension's rather than this site's; a declaration from this site
 * passes a case's value.
 */
final readonly class SettingRequirement implements Requirement
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $directive The directive's name, as `ini_get()` spells it.
     * @param SettingConstraint $constraint What its value has to be. Public so a declaration's
     *                                      floor can be asserted to be derived rather than written
     *                                      out — see `HealthTest`.
     * @param Level $level
     *
     * @throws RequirementException if $directive is empty.
     */
    public function __construct(
        private string $directive,
        public SettingConstraint $constraint,
        private Level $level = Level::Required,
    ) {
        if ($directive === '') {
            throw new RequirementException('A setting requirement needs the name of a php.ini directive.');
        }
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->directive;
    }

    /**
     * @return Area
     */
    public function area(): Area
    {
        return Area::Settings;
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
        return $this->constraint->describe();
    }

    /**
     * @return Finding
     */
    public function check(): Finding
    {
        $configured = ini_get($this->directive);

        return $configured === false
            ? new Finding('no such directive', false)
            : new Finding($configured, $this->constraint->accepts($configured));
    }
}
