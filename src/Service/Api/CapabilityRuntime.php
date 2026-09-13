<?php

declare(strict_types=1);

namespace Phpanta\Service\Api;

use Phpanta\Http\Api\ApiHandler;
use Phpanta\Http\Api\ApiResult;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\ServerVariable;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Health\PhpSetting;
use Phpanta\Support\Collection;

/**
 * The CapabilityRuntime class. The interpreter, the machine it runs on, and that machine's clock.
 *
 * **It does not supersede {@link UpdateVersion} and must not be made to.** That one answers "did my
 * push land" in three lines you read in a second, and it is what a deploy ends with. The two overlap
 * on `PHP_VERSION` and nothing else, and even that costs nothing: both read the same constant
 * rather than two spellings of one fact.
 *
 * **The clock is not decoration**: {@link \Phpanta\Service\ApiGate} refuses a credential whose
 * serial sits more than five minutes from it, and that is cause number two in the list
 * `tools/api.php` prints when a call comes back refused. There is a chicken and an egg in it — a
 * clock far enough out refuses the very call that would report it — but the interesting case is the
 * one that is drifting rather than the one that has already gone, and that is the case this
 * catches. The zone is beside it, because a time without one cannot settle the question.
 *
 * **Everything here is safe to say only because nothing unsigned can reach it.** A PHP version, a
 * SAPI and a server's own uname are reconnaissance; behind the gate they are a report to the one
 * person holding the private key. That is why this is a service under `/api` rather than a public
 * page a monitor would ping.
 *
 * A read: it writes nothing and consumes no serial.
 */
final readonly class CapabilityRuntime implements ApiHandler
{
    /**
     * @return bool
     */
    public function isWrite(): bool
    {
        return false;
    }

    /**
     * @return ApiResult
     */
    public function handle(): ApiResult
    {
        return ApiResult::of(
            HttpStatusCode::Ok,
            HealthSection::facts('interpreter', new Collection(HealthFact::class)->with(
                new HealthFact('version', PHP_VERSION),
                new HealthFact('version id', (string) PHP_VERSION_ID),
                new HealthFact('sapi', PHP_SAPI),
                new HealthFact('zend', zend_version()),
                new HealthFact('os', PHP_OS_FAMILY),
            )),
            HealthSection::facts('host', new Collection(HealthFact::class)->with(
                new HealthFact('server', ServerVariable::ServerSoftware->string() ?? ''),
                new HealthFact('protocol', ServerVariable::ServerProtocol->string() ?? ''),
                new HealthFact('system', php_uname()),
                new HealthFact('clock', date(DATE_ATOM)),
                new HealthFact(PhpSetting::Timezone->value, PhpSetting::Timezone->configured()),
            )),
        );
    }
}
