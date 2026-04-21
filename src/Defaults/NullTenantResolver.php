<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Defaults;

use Devletes\NotificationsMax\Contracts\TenantResolver;

/**
 * Single-tenant default. The package uses this when no host binding is
 * registered, making it safe to run notifications-max out of the box without
 * any tenancy setup.
 */
class NullTenantResolver implements TenantResolver
{
    public function current(): ?object
    {
        return null;
    }

    public function currentId(): ?int
    {
        return null;
    }

    /**
     * Conventional fallback: probe the user's `tenant` relationship for a
     * `slug`. Returns null when the relation is absent or the slug is unset
     * — single-tenant apps and apps without a "tenant slug" concept get
     * exactly the right behaviour without overriding.
     *
     * Multi-tenant installs typically subclass this resolver (or implement
     * {@see TenantResolver} from scratch) when their tenant model lives at
     * a different relation name or stores the public identifier under a
     * different key.
     */
    public function slugFor(object $user): ?string
    {
        $tenant = $user->tenant ?? null;

        if (is_object($tenant) && isset($tenant->slug) && is_string($tenant->slug)) {
            return $tenant->slug;
        }

        return null;
    }

    public function bindForJob(int $tenantId): void
    {
        // No-op. Multi-tenant installs bind their own implementation.
    }
}
