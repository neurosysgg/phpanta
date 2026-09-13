<?php

declare(strict_types=1);

namespace Phpanta\Support;

use Phpanta\App;
use Phpanta\DataFileName;
use Phpanta\Model\Health\ByteFloor;
use Phpanta\Model\Health\ExtensionRequirement;
use Phpanta\Model\Health\Level;
use Phpanta\Model\Health\PhpExtension;
use Phpanta\Model\Health\PhpSetting;
use Phpanta\Model\Health\Requirement;
use Phpanta\Model\Health\SecondsFloor;
use Phpanta\Model\Health\SettingRequirement;
use Phpanta\Model\Health\Toggle;
use Phpanta\Model\Health\VersionRequirement;
use Phpanta\Service\ApiGate;
use Phpanta\Service\Health\DataFileRequirement;
use Phpanta\Service\Health\LogDirectoryRequirement;
use Phpanta\Service\Health\WebrootRequirement;

/**
 * Builds and returns what this installation needs of its host — everything `health v1` checks.
 *
 * **The framework's floor, and the one place it is declared.** A site adds its own requirements in
 * {@link \Phpanta\App::ownRequirements()}, and {@link \Phpanta\App::requirements()} puts the
 * two together. Both are code for the same reason: code ships with the code — `src/` is in every
 * push, `data/` is in none —
 * and a requirement exists because some code needs it, so the two have to arrive together. A floor
 * declared in a data file would reach the server only with `deploy.sh`, and could be in force a week
 * before or after the code that set it.
 *
 * The built-in kinds cover the common cases without a class; see `docs/health.md` for them, for
 * one written by hand, and for the no-throw contract a hand-written one keeps.
 */
final class RequirementInitialization
{
    /**
     * The oldest PHP this site runs on — `composer.json`'s `"php": "^8.5"`, which `HealthTest`
     * holds this to. 8.5 is load-bearing three times over; see CLAUDE.md's Stack.
     */
    private const string PHP = '8.5';

    /**
     * The least `memory_limit` a push fits in.
     *
     * **Derived from {@link ApiGate::MAX_BODY} rather than written out**, because a push is the
     * largest thing a request here holds. At its peak three copies of about that size coexist: the
     * body as read, the tar `gzdecode()` makes of it, and each file's bytes cut out of the tar. An
     * incompressible payload at the cap is therefore about three times it, and the fourth is the
     * interpreter and this codebase. The decoded size has no cap of its own — a key holder can send
     * a payload that expands past any floor — which the pentest considered and accepted, so this is
     * the floor an honest push needs rather than a bound on what a push can ask for.
     */
    private const int PUSH_MEMORY = 4 * ApiGate::MAX_BODY;

    /**
     * The least `max_execution_time` a push fits in, in seconds.
     *
     * PHP's own default, and a push of a few hundred files takes a fraction of it. It is a floor
     * against a host that has been set *below* the default, which is the one way this goes wrong.
     */
    private const int PUSH_SECONDS = 30;

    /** @return Collection<Requirement> */
    public static function requirements(): Collection
    {
        return new Collection(Requirement::class)
            ->with(new VersionRequirement(self::PHP))
            // The five extensions the site is a fatal without, each proved by being used rather
            // than by its name being registered. PhpExtension is the vocabulary, and the one
            // statement of the list that is compared with composer.json in code.
            ->with(...new Collection(PhpExtension::class)
                ->with(...PhpExtension::cases())
                ->map(static fn(PhpExtension $extension): Requirement => new ExtensionRequirement(
                    $extension->value,
                    Level::Required,
                    $extension->isPresent(...),
                ))
                ->toValues())
            ->with(
                // post_max_size spells "no limit" 0 and memory_limit spells it -1, which is why a
                // byte floor is told which rather than guessing.
                new SettingRequirement(PhpSetting::PostMaxSize->value, new ByteFloor(ApiGate::MAX_BODY, 0)),
                new SettingRequirement(PhpSetting::MemoryLimit->value, new ByteFloor(self::PUSH_MEMORY, -1)),
                new SettingRequirement(PhpSetting::MaxExecutionTime->value, new SecondsFloor(self::PUSH_SECONDS, 0)),
                // The pair five docblocks once asserted of the live host. A diagnostic printed into
                // a response lands inside a page already being written; one not logged goes nowhere.
                new SettingRequirement(PhpSetting::DisplayErrors->value, Toggle::Off),
                new SettingRequirement(PhpSetting::LogErrors->value, Toggle::On),
                // Optional: the site is correct without the opcode cache, only slower.
                new SettingRequirement(PhpSetting::OpcacheEnable->value, Toggle::On, Level::Optional),
                // Optional: on, it is a deprecation raised on every request and nothing worse.
                // public/.user.ini turns it off; this is what says whether the host took it.
                new SettingRequirement(PhpSetting::RegisterArgcArgv->value, Toggle::Off, Level::Optional),
            )
            ->with(new WebrootRequirement())
            ->with(...App::current()->dataFiles()
                ->where(static fn(DataFileName $file): bool => $file->isTracked())
                ->map(static fn(DataFileName $file): Requirement => new DataFileRequirement($file))
                ->toValues())
            // Optional: without it the site is correct and its diagnostics go where nobody reads.
            ->with(new LogDirectoryRequirement(App::current()->logs()));
    }
}
