<?php

declare(strict_types=1);

namespace Phpanta\Test\Unit;

use Phpanta\Support\PasswordHash;
use Phpanta\Support\SearchableCollection;

/**
 * The login recipe's users: a digest per name. docs/login.md reads them out of a data file; the
 * test hands them in.
 */
final readonly class UsersFixture
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param SearchableCollection<PasswordHash> $hashes Each user's digest, keyed by name.
     */
    public function __construct(private SearchableCollection $hashes) {}

    /**
     * $name's digest, or null for a name the site does not know.
     *
     * @param string $name
     * @return PasswordHash|null
     */
    public function hash(string $name): ?PasswordHash
    {
        return $this->hashes->find($name);
    }
}
