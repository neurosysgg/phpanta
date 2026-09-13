<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

/**
 * A site's value object, as {@link DatabaseTest} maps a row of notes into one.
 */
final readonly class NoteFixture
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int         $id
     * @param string      $title
     * @param string|null $body
     */
    public function __construct(public int $id, public string $title, public ?string $body) {}
}
