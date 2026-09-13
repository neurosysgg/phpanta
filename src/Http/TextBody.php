<?php

declare(strict_types=1);

namespace Phpanta\Http;

/**
 * The TextBody class. A body that is a string already: a page's markup, a refusal's sentence, or
 * nothing at all.
 */
final readonly class TextBody implements Body
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $text The bytes to send; `''` for an answer with no body — a redirect, a 304, a 401.
     */
    public function __construct(private string $text = '') {}

    /**
     * @return void
     */
    public function emit(): void
    {
        echo $this->text;
    }

    /**
     * @return string
     */
    public function contents(): string
    {
        return $this->text;
    }
}
