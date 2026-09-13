<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\Response;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Support\Collection;

/**
 * The CapabilityExtensions class. Every extension the engine has loaded, and its version.
 *
 * **Registered, which is not the same claim as working.** This is `get_loaded_extensions()`'s
 * answer and nothing more: an extension on this list may still be built without the part an
 * installation reaches for. Whether the ones the framework needs actually work is `health v1
 * extensions`'s question, asked by using them — see {@link \Phpanta\Model\Health\PhpExtension}.
 *
 * Zend extensions — the opcode cache, a debugger — are a second list, because the engine keeps them
 * apart and a reader looking for OPcache will not find it among the others.
 *
 * Sorted case-insensitively, since the engine's own order is the order it happened to load them in.
 *
 * A read: it writes nothing and consumes no serial.
 */
final readonly class CapabilityExtensions implements ApiHandler
{
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
            HealthSection::facts('extensions', self::versions(false)),
            HealthSection::facts('zend extensions', self::versions(true)),
        ));
    }

    /**
     * Every extension of one kind, named, with the version it reports — or a dash, for one that
     * reports none.
     *
     * @param bool $zend
     * @return Collection<HealthFact>
     */
    private static function versions(bool $zend): Collection
    {
        $names = get_loaded_extensions($zend);
        sort($names, SORT_STRING | SORT_FLAG_CASE);

        $facts = [];
        foreach ($names as $name) {
            $facts[] = new HealthFact($name, (string) phpversion($name));
        }

        return new Collection(HealthFact::class)->with(...$facts);
    }
}
