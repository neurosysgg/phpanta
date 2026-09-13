<?php

declare(strict_types=1);

namespace Phpanta\Tool\Update;

/**
 * The PackedFile class. One file on its way into an archive: the name it will carry there, and its
 * bytes.
 *
 * The name is the *archive's* name — `public/index.php` — not the path it was read from, and the
 * two differ on the webroot, whose directory is called `public/` here and may be called anything
 * on the live host — `htdocs/`, or the site's own name. Keeping them apart in a value object is
 * what makes that mapping happen once, in {@link TarWriter::tree()}, rather than at every call site
 * that builds a payload.
 */
final readonly class PackedFile
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $name The member name, always relative and forward-slashed.
     * @param string $contents
     */
    public function __construct(public string $name, public string $contents) {}
}
