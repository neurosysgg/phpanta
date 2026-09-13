<?php

declare(strict_types=1);

namespace Phpanta\Form;

use NoDiscard;
use Phpanta\Text\FrameworkText;
use Phpanta\Text\Translatable;

/**
 * The Email rule. The field is shaped like an email address — which is all any check can say.
 *
 * PHP's own `FILTER_VALIDATE_EMAIL`, and its limits are worth knowing before relying on it:
 *
 * - **It says nothing about whether the address exists** or whether anyone reads it. Only a message
 *   sent to it and answered proves that; a form that needs to know sends one.
 * - **It refuses some addresses that are real.** A domain without a dot (`user@localhost`), a quoted
 *   local part (`"a b"@example.org`), and any non-ASCII letter on either side of the `@` — so
 *   `müller@example.de` is refused though mail servers may accept it, and a site whose visitors
 *   write such addresses needs a rule of its own.
 * - It refuses a local part over sixty-four characters and a trailing newline, as it should.
 */
final readonly class Email implements Rule
{
    /**
     * @param string $value
     * @return Translatable|null
     */
    #[NoDiscard('check() only asks; a call whose result goes nowhere checked nothing')]
    public function check(string $value): ?Translatable
    {
        if ($value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? FrameworkText::FieldNotEmail : null;
    }
}
