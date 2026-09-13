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
use Phpanta\Service\UpdateApplier;

/**
 * Builds and returns what this installation needs of its host — everything `health v1` checks.
 *
 * **The framework's floor, and the one place it is declared.** A site adds its own requirements in
 * {@link \Phpanta\App::ownRequirements()}, and {@link \Phpanta\App::requirements()} puts the
 * two together. Both are code for the same reason: code ships with the code — `src/` is in every
 * push, `data/` is in none —
 * and a requirement exists because some code needs it, so the two have to arrive together. A floor
 * declared in a data file would reach the server only with a full deploy, and could be in force a week
 * before or after the code that set it.
 *
 * The built-in kinds cover the common cases without a class; see `docs/health.md` for them, for
 * one written by hand, and for the no-throw contract a hand-written one keeps.
 */
final class RequirementInitialization
{
    /**
     * The oldest PHP the framework runs on — `composer.json`'s `"php": "^8.5"`, which `HealthTest`
     * holds this to. 8.5 is load-bearing three times over; see CLAUDE.md's Stack.
     */
    private const string PHP = '8.5';

    /**
     * The least `memory_limit` a push fits in.
     *
     * **Derived from the two caps rather than written out**, because a push is the largest thing a
     * request here holds, and both of its sizes are bounded before they are held. At its peak four
     * things coexist: the body as read ({@link ApiGate::MAX_BODY} at most), the tar `gzdecode()`
     * makes of it ({@link \Phpanta\Service\UpdateApplier::MAX_EXPANDED} at most, the cap it is
     * decoded under), each file's bytes cut out of that tar (as much again), and the interpreter and
     * the codebase (about a body's worth). Both caps being enforced, this is a bound on what any push
     * can make a request hold, not only the floor an honest one needs.
     */
    private const int PUSH_MEMORY = 2 * ApiGate::MAX_BODY + 2 * UpdateApplier::MAX_EXPANDED;

    /**
     * The least `max_execution_time` a push fits in, in seconds.
     *
     * PHP's own default, and a push of a few hundred files takes a fraction of it. It is a floor
     * against a host that has been set *below* the default, which is the one way this goes wrong.
     */
    private const int PUSH_SECONDS = 30;

    /**
     * @param App $app The app whose deployment the data-file and log requirements are about —
     *                 handed in by {@link App::requirements()} rather than read off
     *                 {@link App::current()}, so the floor of one app never quietly checks another's.
     * @return Collection<Requirement>
     */
    public static function requirements(App $app): Collection
    {
        return new Collection(Requirement::class)
            ->with(new VersionRequirement(self::PHP))
            // The five extensions the framework is a fatal without, each proved by being used rather
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
                // A diagnostic printed into a response lands inside a page already being written;
                // one not logged goes nowhere.
                new SettingRequirement(PhpSetting::DisplayErrors->value, Toggle::Off),
                new SettingRequirement(PhpSetting::LogErrors->value, Toggle::On),
                // Optional: an app is correct without the opcode cache, only slower.
                new SettingRequirement(PhpSetting::OpcacheEnable->value, Toggle::On, Level::Optional),
                // Optional: on, it is a deprecation raised on every request and nothing worse.
                // public/.user.ini turns it off; this is what says whether the host took it.
                new SettingRequirement(PhpSetting::RegisterArgcArgv->value, Toggle::Off, Level::Optional),
            )
            ->with(new WebrootRequirement())
            ->with(...$app->dataFiles()
                ->where(static fn(DataFileName $file): bool => $file->isTracked())
                ->map(static fn(DataFileName $file): Requirement => new DataFileRequirement($file))
                ->toValues())
            // Optional: without it an app is correct and its diagnostics go where nobody reads.
            ->with(new LogDirectoryRequirement($app->logs()));
    }
}
