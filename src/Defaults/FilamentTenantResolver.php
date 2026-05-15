<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Defaults;

use Devletes\NotificationsMax\Contracts\TenantResolver;
use Filament\Facades\Filament;

/**
 * Default {@see TenantResolver} for installs running on Filament — i.e.
 * everyone, since this package requires `filament/filament: ^5.0`. Reads
 * the current tenant directly off Filament's manager facade, so the host
 * doesn't have to write a custom resolver class.
 *
 * Resolution semantics:
 *
 *   - Inside an HTTP request on a tenanted panel: returns the tenant
 *     {@see \Filament\Http\Middleware\IdentifyTenant} bound for the request.
 *   - On a non-tenanted panel (single-tenant install / panels without
 *     `->tenant()` configured): returns null.
 *   - In a queue worker / scheduled command / CLI context with no panel
 *     bound: returns null on the first call. The package's queue
 *     middleware {@see \Devletes\NotificationsMax\Queue\RestoreTenantContext}
 *     is what binds tenant context inside SendBroadcastJob; once it runs,
 *     subsequent {@see current()} calls within that job's execution
 *     return the bound tenant.
 *
 * Hosts running non-Filament tenancy (custom tenancy package, multi-
 * database tenancy, etc.) point `notifications-max.resolvers.tenant`
 * at their own implementation. The contract is three read-only
 * methods — there's no lifecycle the host has to manage.
 */
class FilamentTenantResolver implements TenantResolver
{
    public function current(): ?object
    {
        return Filament::getTenant();
    }

    public function currentId(): ?int
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return null;
        }

        $key = method_exists($tenant, 'getKey') ? $tenant->getKey() : null;

        return is_numeric($key) ? (int) $key : null;
    }

    /**
     * Conventional fallback for the slug: probe the user's `tenant`
     * relationship for a `slug` attribute. Used by the dispatcher to
     * stamp `tenant_slug` onto notification context when there's no
     * current panel-bound tenant — e.g. in a scheduled command or
     * tinker session.
     *
     * Apps whose user model exposes the tenant slug via a different
     * relation name or a denormalised column override this method by
     * extending the resolver or implementing {@see TenantResolver}
     * directly.
     */
    public function slugFor(object $user): ?string
    {
        $tenant = $user->tenant ?? null;

        if (is_object($tenant) && isset($tenant->slug) && is_string($tenant->slug)) {
            return $tenant->slug;
        }

        return null;
    }
}
