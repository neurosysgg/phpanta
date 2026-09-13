<?php

declare(strict_types=1);

namespace Phpanta\Http\Security;

use Phpanta\Exception\SecurityPolicyException;
use Phpanta\Http\HeaderValue;
use Phpanta\Support\Collection;

/**
 * The PermissionsPolicy class. A `Permissions-Policy` header built from typed features.
 *
 * The framework asks for none of these, so the only thing it ever expresses is denial — hence a
 * single {@link self::deny()} constructor rather than a general allow-list builder. If a
 * feature ever needs granting, that is a new named constructor, not a string edit.
 */
final readonly class PermissionsPolicy implements HeaderValue
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<PermissionsPolicyFeature> $denied
     */
    private function __construct(private Collection $denied) {}

    /**
     * Denies the given features to every origin, this one included.
     *
     * @param PermissionsPolicyFeature ...$features
     * @return self
     * @throws SecurityPolicyException if no features are given — an empty Permissions-Policy
     *                                 header is not a weaker policy, it is a malformed one.
     */
    public static function deny(PermissionsPolicyFeature ...$features): self
    {
        if ($features === []) {
            throw new SecurityPolicyException(
                'PermissionsPolicy::deny() needs at least one feature; omit the header instead.',
            );
        }

        return new self(new Collection(PermissionsPolicyFeature::class)->with(...$features));
    }

    /**
     * Denies every feature {@link PermissionsPolicyFeature} knows about.
     *
     * @return self
     */
    public static function denyAll(): self
    {
        return self::deny(...PermissionsPolicyFeature::cases());
    }

    /**
     * Returns the header value: `geolocation=(), camera=(), …`.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->denied
            ->map(static fn(PermissionsPolicyFeature $feature): string => $feature->denied())
            ->join(', ');
    }
}
