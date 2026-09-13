<?php

declare(strict_types=1);

namespace Phpanta\Tool\Command;

use Phpanta\Tool\Cli\Option;

/**
 * The PushUpdateOption enum. The flags `push-update` accepts.
 *
 * Declared so {@link \Phpanta\Tool\Cli\Input} can refuse one this command never named — which for
 * this command is worth more than for the others here. A mistyped `--dry-run` that were silently
 * dropped would not print a plan; it would deploy.
 */
enum PushUpdateOption: string implements Option
{
    /** Validate and report on the far side, writing nothing and advancing no serial. */
    case DryRun = 'dry-run';

    /**
     * Leave alone whatever the payload does not mention.
     *
     * The default is to mirror, matching a full deploy's `--delete` on the trees this ships. This
     * flag is the escape hatch for a push that is deliberately partial.
     */
    case NoMirror = 'no-mirror';

    /**
     * Ship the framework as it stands in `phpanta/` — or none, if none is checked out.
     *
     * Without it, a push refuses a framework that is missing, has changes that are not committed, or
     * is not the commit the site's HEAD records: each would put code on the server that no checkout
     * of the site reproduces. See {@link \Phpanta\Tool\Update\FrameworkCheckout}.
     */
    case AnyFramework = 'any-framework';

    /**
     * Which deployment to push to. Defaults to the live site.
     *
     * An **origin** rather than an endpoint: the path is derived from the typed action, so naming a
     * full endpoint here would be the address written twice.
     */
    case Url = 'url';

    /** The private key. Defaults to the one the command was given, under `$HOME`. */
    case Key = 'key';

    /**
     * @return string
     */
    public function flag(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function takesValue(): bool
    {
        return $this === self::Url || $this === self::Key;
    }
}
