<?php

declare(strict_types=1);

namespace Phpanta\Model\Health;

/**
 * The Toggle enum. A switch directive — `display_errors`, `log_errors` — that has to be on, or off.
 *
 * An enum implementing {@link SettingConstraint} rather than a class taking a bool, because there
 * are exactly two constraints of this kind and a declaration reads better naming one:
 * `Toggle::Off` against `new Toggle(false)`.
 *
 * **Read the way PHP reads a boolean directive**, with `filter_var()`'s boolean filter: `1`, `On`,
 * `yes` and `true` are on, and `0`, `Off`, `no`, `false` and `''` are off. **Anything else meets
 * neither** — and that is not pedantry. `display_errors` accepts `stderr`, which is neither on nor
 * off in the sense a health check means: it is on, somewhere else. A switch that read it as off would
 * pass exactly the host this check is for.
 */
enum Toggle: string implements SettingConstraint
{
    case On = 'on';

    case Off = 'off';

    /**
     * @param string $configured
     * @return bool
     */
    public function accepts(string $configured): bool
    {
        return filter_var($configured, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === ($this === self::On);
    }

    /**
     * @return string
     */
    public function describe(): string
    {
        return $this->value;
    }
}
