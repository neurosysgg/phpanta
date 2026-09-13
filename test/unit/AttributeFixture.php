<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\View\Html\AttributeName;

/**
 * A custom element's attributes for the tests: one that carries an address and one that does not.
 *
 * The address is the case that matters. The scheme check keys on {@link AttributeName::isUrl()},
 * never on a list of HTML's own names, so an attribute a site declares as an address has to be
 * refused exactly as `href` is.
 */
enum AttributeFixture: string implements AttributeName
{
    case Source  = 'source';
    case Caption = 'caption';

    /**
     * @return string
     */
    public function attribute(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function isUrl(): bool
    {
        return $this === self::Source;
    }
}
