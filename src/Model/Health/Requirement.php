<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

/**
 * The Requirement interface. One thing an installation needs of the host it runs on, and how to
 * ask whether it has it.
 *
 * **This is the extension point.** The built-in kinds — {@link VersionRequirement},
 * {@link ExtensionRequirement}, {@link SettingRequirement} — cover what most installations need
 * without a line of code, and anything they cannot say is a class implementing this. The
 * framework's own deployment checks are written that way ({@link \Phpanta\Service\Health\WebrootRequirement},
 * {@link \Phpanta\Service\Health\DataFileRequirement}), so the extension point has been used by
 * the code that defines it rather than only described. Declarations live in
 * {@link \Phpanta\Support\RequirementInitialization}; see `docs/health.md`.
 *
 * **Nothing under `Model\Health` knows which installation it is checking.** No class here imports
 * the app, a data-file name or anything else of an installation's — that is what keeps the core
 * independent of every one, and a requirement that needs to know about its installation lives
 * outside it, under `Service\Health` or in the site itself.
 *
 * **{@link self::check()} must not throw.** A requirement that cannot tell reports that it cannot
 * tell, as a {@link Finding} that is not met — the refusal's own sentence is usually the most useful
 * thing it could say. The report does not catch on an implementation's behalf, and cannot: the
 * framework refuses `catch (Throwable)` outright (see `docs/guidelines.md`), so a requirement that
 * throws turns the whole report into a 500 rather than one line into a `fail`.
 */
interface Requirement
{
    /**
     * What is being checked, as the report's name column shows it: an extension's name, a
     * directive's, a file's. Wherever the thing has a vocabulary of its own, that vocabulary's
     * spelling rather than a caption for it.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Which address this is checked under.
     *
     * @return Area
     */
    public function area(): Area;

    /**
     * Whether going unmet is a `503` or a warning.
     *
     * @return Level
     */
    public function level(): Level;

    /**
     * The floor, stated for a human: `8.5.0 or later`, `at least 8M`, `off`.
     *
     * @return string
     */
    public function expected(): string;

    /**
     * Asks the host. Runs every time it is called, and only when it is called — a requirement is
     * declared on every request that builds the set and checked only on the one that asks.
     *
     * @return Finding
     */
    public function check(): Finding;
}
