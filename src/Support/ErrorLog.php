<?php

declare(strict_types=1);

namespace Phpanta\Support;

use DateTimeImmutable;
use Phpanta\Model\Health\PhpSetting;

/**
 * The ErrorLog class. Points PHP's own diagnostics at a file this deployment owns.
 *
 * **Set per request, because that is the one place every runtime can be told the same thing.**
 * Strato's `error_log` is empty, which sends a diagnostic to the SAPI's own log where nothing here
 * can read it, and its `error_reporting` drops notices and deprecations. The local Apache names
 * `/var/log/php_errors.log`, which php-fpm's `http` user cannot open, so its diagnostics went the
 * same way. Both directives are `PHP_INI_ALL`, so a script can set them for itself, and
 * `capability v1 errors` then quotes the file — see docs/runtime.md.
 *
 * **Not in `public/.user.ini`**, although that would also catch what PHP raises before the script
 * starts. A path there has to be absolute, and the live host spells one directory two ways — see
 * {@link \Phpanta\App::webroot()}; a relative one resolves against the working directory at the
 * moment of logging, which is not the same directory at startup as during the script. So a
 * diagnostic raised before `index.php` runs still goes to the host's log. That is the cost.
 *
 * **One file a month**, because PHP only ever appends and a log nobody rotates is a quota problem
 * waiting for a noisy month. A month in the name is the cheapest rotation there is: decided per
 * request, with nothing to run and nothing to schedule.
 *
 * **A missing or unwritable directory is not an error here.** PHP falls back to the SAPI's log when
 * it cannot open the file, silently. That silence is why `health v1` carries
 * {@link \Phpanta\Service\Health\LogDirectoryRequirement}, and nothing here creates the directory,
 * for the reason {@link File} gives.
 */
final class ErrorLog
{
    /**
     * Everything, deprecations and notices included — the two classes Strato's own mask drops.
     * A deprecation is how a PHP upgrade announces what it is about to break, and the one this
     * host raised on every request (`register_argc_argv`) was found by accident.
     */
    public const int REPORTING = E_ALL;

    /**
     * The file a diagnostic raised at $when is written to.
     *
     * @param Directory         $logs Where the logs live — `App::logs()`.
     * @param DateTimeImmutable $when
     * @return File
     */
    public static function file(Directory $logs, DateTimeImmutable $when): File
    {
        return $logs->file('php-' . $when->format('Y-m') . '.log');
    }

    /**
     * Sends every diagnostic from here on to $file.
     *
     * @param File $file
     * @return void
     */
    public static function install(File $file): void
    {
        ini_set(PhpSetting::ErrorLog->value, $file->path);
        error_reporting(self::REPORTING);
    }
}
