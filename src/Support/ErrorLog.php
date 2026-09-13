<?php

declare(strict_types=1);

namespace Phpanta\Support;

use DateTimeImmutable;
use NoDiscard;
use Phpanta\Exception\SiteException;
use Phpanta\Model\Health\PhpSetting;
use Throwable;

/**
 * The ErrorLog class. Points PHP's own diagnostics at a file this deployment owns.
 *
 * **Set per request, because that is the one place every runtime can be told the same thing.**
 * A shared host may leave `error_log` empty, which sends a diagnostic to the SAPI's own log where
 * nothing here can read it, and mask notices and deprecations out of `error_reporting`. A local
 * server may name a log its PHP user cannot open, so its diagnostics go the same way. Both
 * directives are `PHP_INI_ALL`, so a script can set them for itself, and `capability v1 errors`
 * then quotes the file.
 *
 * **Not in `public/.user.ini`**, although that would also catch what PHP raises before the script
 * starts. A path there has to be absolute, and a shared host can spell one directory two ways — see
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
     * Everything, deprecations and notices included — the two classes a shared host's own mask may
     * drop. A deprecation is how a PHP upgrade announces what it is about to break, and one raised
     * on every request — `register_argc_argv` under 8.5 — is otherwise found only by accident.
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

    /**
     * The line a fault the request did not survive is logged as: which app, what was thrown,
     * whether it came from code that marks its exceptions as its own, where, and what it said.
     *
     * The same shape as a front controller's last-resort handler writes, so the log reads alike
     * whichever of the two caught it. {@link SiteException} is the one type named, and it is the
     * first question an operator asks: this repository's mistake, or something underneath it.
     *
     * @param Throwable $fault
     * @param string    $app   The app's name, which leads the line.
     * @return string
     */
    #[NoDiscard('faultLine() writes nothing; a call whose result goes nowhere logged nothing')]
    public static function faultLine(Throwable $fault, string $app): string
    {
        return sprintf(
            '%s: uncaught %s %s at %s:%d — %s',
            $app,
            $fault::class,
            $fault instanceof SiteException ? '(from this repository)' : '(from underneath it)',
            $fault->getFile(),
            $fault->getLine(),
            $fault->getMessage(),
        );
    }
}
