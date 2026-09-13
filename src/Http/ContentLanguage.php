<?php

declare(strict_types=1);

namespace Phpanta\Http;

use Phpanta\Text\Language;

/**
 * The ContentLanguage class. The value of a `Content-Language` header: the language a body is in.
 *
 * A class rather than the enum's value written where it is sent, for the reason every header
 * value here is one — {@link Header} takes a {@link HeaderValue}, so what a header may say is
 * decided by its type rather than by whichever string a call site assembled.
 */
final readonly class ContentLanguage implements HeaderValue
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Language $language
     */
    public function __construct(private Language $language) {}

    /**
     * @return string
     */
    public function render(): string
    {
        return $this->language->value;
    }
}
