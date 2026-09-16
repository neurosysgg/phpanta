<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

use Phpanta\Model\Machine\Measure;

/**
 * The DropTerms class. How a drop's terms are said — when it goes, how often it opens, what it needs —
 * wherever the admin says them: a drop being made, and a drop listed.
 *
 * One class, so the two answers say one thing one way.
 */
final class DropTerms
{
    /**
     * When it goes.
     *
     * @param int $expires
     * @return string
     */
    public static function gone(int $expires): string
    {
        return sprintf('gone at %s', Measure::moment($expires));
    }

    /**
     * How often it opens.
     *
     * @param bool $once
     * @return string
     */
    public static function opens(bool $once): string
    {
        return $once ? 'gone once it has been read' : 'opens until then, as often as it is asked';
    }

    /**
     * What it needs to open.
     *
     * @param bool $locked
     * @return string
     */
    public static function needs(bool $locked): string
    {
        return $locked ? 'needs its password as well as its link' : 'opens with its link alone';
    }
}
