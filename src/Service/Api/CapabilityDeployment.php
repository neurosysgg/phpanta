<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\App;
use Phpanta\DataFileName;
use Phpanta\Exception\UpdateException;
use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Response;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Update\UpdateRoot;
use Phpanta\Support\Collection;

/**
 * The CapabilityDeployment class. Where this installation serves from, and which of the files it
 * reads are there.
 *
 * **Every file, tracked or not, and no verdict.** The tracked ones are also requirements — an
 * absence is a fault, and `health v1 deployment` fails it — but the untracked four each have their
 * own reason to be absent: no demos staged, no gate, no log yet. So this reports state, and the
 * tracked flag beside each line is what lets `absent  (tracked)` read as the fault it is without
 * this class inventing a severity word for it.
 *
 * **{@link DataFile::UpdateKey} can never be reported absent**, and that is worth knowing rather
 * than confusing: a report anybody is reading verified against it. The size beside it is what tells
 * a whole key from a truncated paste.
 *
 * **The replay serial is deliberately not here**, though a deployment section is exactly where one
 * would expect it. `update v1 version` reports it, and a second report of it would be a second place
 * to keep in step.
 *
 * A read: it writes nothing and consumes no serial, and touches only files it asks the size of.
 */
final readonly class CapabilityDeployment implements ApiHandler
{
    /** What a file says when it is there. */
    private const string PRESENT = 'present';

    /** What a file says when it is not. Whether that is a fault is the next column. */
    private const string ABSENT = 'absent';

    /** The repository carries this file, so every clone has it and an absence is a fault. */
    private const string TRACKED = '  (tracked)';

    /** It does not, so an absence is a state: no demos staged, no gate, no key. */
    private const string UNTRACKED = '  (untracked)';

    /**
     * @return bool
     */
    public function isWrite(): bool
    {
        return false;
    }

    /**
     * @return Response
     */
    public function handle(): Response
    {
        return new PlainTextResponse(HttpStatusCode::Ok, HealthSection::document(
            HealthSection::facts('deployment', new Collection(HealthFact::class)
                ->with(new HealthFact('webroot', self::webroot()))
                ->with(new HealthFact(UpdateRoot::Framework->value, self::framework()))
                ->with(...App::current()->dataFiles()
                    ->map(static fn(DataFileName $file): HealthFact => new HealthFact($file->value, self::state($file)))
                    ->toValues())),
        ));
    }

    /**
     * Where this deployment serves from, or why it cannot say.
     *
     * Caught rather than allowed to propagate, for the reason
     * {@link \Phpanta\Service\Health\WebrootRequirement} states: left alone the refusal would turn
     * the whole answer into a 422, and its own sentence is the most useful thing on this line.
     *
     * @return string
     */
    private static function webroot(): string
    {
        try {
            return App::current()->webroot()->path;
        } catch (UpdateException $refusal) {
            return $refusal->getMessage();
        }
    }

    /**
     * Where the framework is deployed, or that it is not yet.
     *
     * Its own line because the first push that carries it is the one worth checking: a deployment
     * whose updater knows the root but has never been sent it answers `absent`, and one that has
     * answers the directory.
     *
     * @return string
     */
    private static function framework(): string
    {
        $directory = App::current()->above()->directory(UpdateRoot::Framework->value);

        return $directory->exists() ? $directory->path : self::ABSENT;
    }

    /**
     * Whether one of the files this site reads is there, and whether the repository carries it.
     *
     * @param DataFileName $file
     * @return string
     */
    private static function state(DataFileName $file): string
    {
        $handle = App::current()->dataFile($file);
        $state  = $handle->exists() ? self::PRESENT . '  ' . $handle->size() : self::ABSENT;

        return $state . ($file->isTracked() ? self::TRACKED : self::UNTRACKED);
    }
}
