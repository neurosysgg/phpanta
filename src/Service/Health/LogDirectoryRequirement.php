<?php

declare(strict_types=1);

namespace Phpanta\Service\Health;

use Phpanta\Model\Health\Area;
use Phpanta\Model\Health\Finding;
use Phpanta\Model\Health\Level;
use Phpanta\Model\Health\Requirement;
use Phpanta\Support\Directory;

/**
 * The LogDirectoryRequirement class. `data/logs/` is there, and PHP can write its log into it.
 *
 * **The one requirement whose failure would otherwise be silent in both directions.**
 * {@link \Phpanta\Support\ErrorLog} points `error_log` at a file in this directory, and when PHP
 * cannot open that file it says nothing and hands the diagnostic to the SAPI's log instead — so a
 * missing directory reads exactly like an app that raises nothing. The directory is gitignored and
 * a deploy need not carry it, so it exists only where somebody made it.
 *
 * **Optional**, because an app is correct without it and only harder to diagnose: a `warn`, never
 * a 503.
 *
 * The directory comes in by constructor rather than from {@link \Phpanta\App::logs()}, so a test can
 * hand it one that is missing, or one it cannot write, without touching the repository's own.
 */
final readonly class LogDirectoryRequirement implements Requirement
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Directory $directory The log directory — `App::logs()`.
     */
    public function __construct(private Directory $directory) {}

    /**
     * @return string
     */
    public function name(): string
    {
        return 'logs/';
    }

    /**
     * @return Area
     */
    public function area(): Area
    {
        return Area::Deployment;
    }

    /**
     * @return Level
     */
    public function level(): Level
    {
        return Level::Optional;
    }

    /**
     * @return string
     */
    public function expected(): string
    {
        return 'writable, so PHP can log into it';
    }

    /**
     * Missing and unwritable are told apart, because the fix differs: one is a `mkdir`, the other
     * a `chgrp` — a server's PHP often runs as a different user from the one who owns the files.
     *
     * @return Finding
     */
    public function check(): Finding
    {
        if (!$this->directory->exists()) {
            return new Finding('no directory there', false);
        }

        return $this->directory->isWritable()
            ? new Finding('writable', true)
            : new Finding('not writable by this process', false);
    }
}
