<?php

declare(strict_types=1);

namespace Phpanta\Model\Drop;

use SensitiveParameter;

/**
 * The DropKeys class. The two keys one drop is sealed under: one for its description, one for its
 * bytes — drawn together, from its link, its password and the deployment's key, by
 * {@link \Phpanta\Service\Drop\DropCipher::keys()}.
 *
 * Two rather than one so that no nonce is ever used twice under a key: each chunk counts from zero,
 * and so does the description.
 */
final readonly class DropKeys
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $meta The description's key.
     * @param string $data The bytes' key.
     */
    public function __construct(
        #[SensitiveParameter] public string $meta,
        #[SensitiveParameter] public string $data,
    ) {}
}
