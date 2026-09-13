<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\App;
use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Response;
use Phpanta\Support\File;

/**
 * The UpdateVersion class. What is actually deployed here.
 *
 * It exists because "did my push land" had no cheap answer. The old one was to `curl` the home page
 * and read the build stamp out of a `<script src>` — which works, needs no key, and tells you
 * nothing at all when the site is behind the pre-launch gate or when the thing that broke is why
 * the home page will not render.
 *
 * **Three facts, chosen because between them they answer that question and no more.** The serial
 * says which push was last accepted; the entry URL carries the build stamp, which is the one thing
 * that distinguishes a deploy that wrote from one that found every file already current; and the
 * PHP version is the fact this repository has twice had to check on the live host by hand, both
 * times before relying on an extension.
 *
 * **It reports the entry URL rather than the stamp inside it**, deliberately. Extracting the stamp
 * means a pattern here that has to agree with the one `tools/build-assets.mjs` writes, in another
 * language, with nothing between them — the drift this codebase spends most of its types avoiding.
 * The whole URL needs no pattern and is directly comparable with what a browser is served.
 *
 * A read: it writes nothing, consumes no serial, and touches one file — which is the standard an
 * action reachable by a replayed credential has to meet. See {@link ApiHandler::isWrite()}.
 */
final readonly class UpdateVersion implements ApiHandler
{
    /**
     * How the three facts are laid out.
     *
     * One format string rather than a caption per line, which is not only brevity: `serial` is a
     * key {@link \Phpanta\Model\Api\ApiEnvelope} already writes, and the same word as a literal in
     * a second class under `src/` is exactly what `GuidelineTest`'s two-files clause is for. Inside
     * a template it is prose, the way it is inside this sentence.
     */
    private const string REPORT = "serial %s\nentry  %s\nphp    %s\n";

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $serial Where the last accepted serial is recorded. A test seam, the way
     *                          {@link \Phpanta\Service\ApiGate}'s is; production passes nothing.
     */
    public function __construct(private ?File $serial = null) {}

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
        $recorded = ($this->serial ?? App::current()->updateSerial())->read();

        return new PlainTextResponse(HttpStatusCode::Ok, sprintf(
            self::REPORT,
            // Trimmed rather than cast: a deployment that has never accepted a push has no record,
            // and `0` would be a serial it is claiming to have seen. The dash says there is none.
            trim($recorded ?? '') ?: '-',
            App::current()->buildId(),
            PHP_VERSION,
        ));
    }
}
