<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

/**
 * The SettingConstraint interface. What a php.ini directive's value has to be for a
 * {@link SettingRequirement} to be met.
 *
 * Its own type rather than a closure on the requirement, because a constraint has to *say* what it
 * wants as well as test for it: the report prints the floor beside the value, and a closure has no
 * way to describe itself. Three implementations cover php.ini's three shapes of value —
 * {@link ByteFloor} for sizes, {@link SecondsFloor} for durations, {@link Toggle} for switches — and
 * a directive with a grammar of its own is a fourth class implementing this.
 */
interface SettingConstraint
{
    /**
     * How a floor states the value its directive spells "no limit" with, after the floor itself.
     *
     * Here rather than in each floor, so {@link ByteFloor} and {@link SecondsFloor} phrase the same
     * idea the same way — `at least 8M or 0 for no limit` — and a third floor inherits the wording
     * rather than inventing its own.
     */
    public const string NO_LIMIT = ' or %d for no limit';

    /**
     * Whether a directive's configured value meets this.
     *
     * @param string $configured The directive's value as `ini_get()` answers it — never `false`,
     *                           which {@link SettingRequirement} has already turned into a failure.
     * @return bool
     */
    public function accepts(string $configured): bool;

    /**
     * The constraint, stated for a human.
     *
     * @return string
     */
    public function describe(): string;
}
