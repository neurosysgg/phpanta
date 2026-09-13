<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

/**
 * The PhpSetting enum. The php.ini directives this site names — in a requirement it declares, or in
 * a line `capability` reports on its own.
 *
 * **A directive name is typed here for the reason every name in this codebase is: getting one
 * wrong is silent.** `ini_get()` answers `false` for a directive that does not exist, which is
 * exactly what it answers for one that exists and is unset — so `ini_get('memory_limmit')` is not
 * an error anywhere, it is a report saying the limit is not configured. That is the same shape of
 * failure {@link \Phpanta\Http\ServerVariable} was written against, one layer down: a misspelling
 * indistinguishable from an absence. {@link SettingRequirement} closes the other half, by failing a
 * directive the engine does not know.
 *
 * These are not settings this repository owns, which is the whole point of reading them.
 * Deliberately not exhaustive: `capability v1 settings` lists every directive the engine has, and a
 * case here is a directive this site has something to say about.
 */
enum PhpSetting: string
{
    /** What one request may allocate. A push holds the whole payload and its expansion at once. */
    case MemoryLimit = 'memory_limit';

    /**
     * The most a request body may weigh before PHP discards it.
     *
     * The ceiling {@link \Phpanta\Service\ApiGate::MAX_BODY} exists to sit under: a bound the
     * application states is worth more than one inherited from a php.ini nobody here owns — but
     * the inherited one still decides whether a push arrives at all, and it is the value that
     * would silently truncate one.
     */
    case PostMaxSize = 'post_max_size';

    /** How long a request may run. A push writes a few hundred files inside one. */
    case MaxExecutionTime = 'max_execution_time';

    /**
     * The clock's zone.
     *
     * Not decoration: `/api` refuses a credential whose serial sits more than
     * {@link \Phpanta\Service\ApiGate::MAX_SKEW} seconds from this clock, and a report that gives
     * a server time without saying which zone it is in cannot settle that question.
     */
    case Timezone = 'date.timezone';

    /**
     * Whether the opcode cache is on — this site's one optional requirement.
     *
     * The one directive here that can genuinely be absent rather than merely unset — a PHP built
     * without the extension has no such name at all — which {@link SettingRequirement} reports as
     * the failure it is and {@link self::configured()} casts to a dash.
     */
    case OpcacheEnable = 'opcache.enable';

    /**
     * Whether a diagnostic is printed into the response.
     *
     * The first half of the pair five docblocks in this repository once asserted about the live
     * host, and which is now a requirement instead. It has to be off there:
     * `SecurityHeaders::send()` and the doctype have both gone out long before most of what could
     * warn, so a printed warning lands inside a page that is already being written.
     */
    case DisplayErrors = 'display_errors';

    /** Whether a diagnostic is recorded at all. Off, and errors go nowhere in either direction. */
    case LogErrors = 'log_errors';

    /**
     * Whether a web request's query string becomes `$_SERVER['argv']`.
     *
     * Deprecated in PHP 8.5 and on at the live host, so every request there raised the deprecation
     * at startup — the last diagnostic `capability v1 errors` reported, and nothing on the web side
     * reads argv. `public/.user.ini` turns it off; the CLI registers argv regardless.
     */
    case RegisterArgcArgv = 'register_argc_argv';

    /**
     * Where a recorded diagnostic goes, and the other half of that pair.
     *
     * Empty is a real answer rather than a missing one: it means the SAPI's own destination, which
     * under `cgi-fcgi` on shared hosting is a log this repository has no path to. That is the
     * measured claim underneath "it is the only account of the run there will be" on
     * {@link \Phpanta\Model\Update\UpdateReport} — and where it is *not* empty, it names the one
     * file worth reading when something has gone wrong, which `capability v1 errors` quotes.
     */
    case ErrorLog = 'error_log';

    /**
     * This directive's configured value, or `''` where there is none.
     *
     * **Cast rather than branched, deliberately.** `ini_get()` answers `string|false`, and for
     * every case above but {@link self::OpcacheEnable} the `false` cannot happen — the names are
     * real, which is what this enum is for — so a guard would be a line no test could reach.
     * `false` casts to `''`, which is already a report's word for "nothing to say" and which
     * {@link HealthFact} renders as a dash.
     *
     * @return string
     */
    public function configured(): string
    {
        return (string) ini_get($this->value);
    }
}
