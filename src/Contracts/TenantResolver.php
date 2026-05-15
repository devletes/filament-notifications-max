<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Contracts;

/**
 * Read-only view onto the host's tenant context. Three methods, no
 * lifecycle — the package handles queue-worker tenant restoration via
 * {@see \Devletes\NotificationsMax\Queue\RestoreTenantContext} middleware
 * so hosts don't need to write bind / unbind glue.
 *
 * Default implementation: {@see \Devletes\NotificationsMax\Defaults\FilamentTenantResolver},
 * which reads directly from Filament's manager facade. Works out of the
 * box for the typical Filament-tenancy install — no override required.
 *
 * Hosts on non-Filament tenancy (custom tenancy package, multi-database
 * tenancy, etc.) point `notifications-max.resolvers.tenant` at their own
 * implementation.
 */
interface TenantResolver
{
    /**
     * The current tenant model, or null when no tenant is bound (single-
     * tenant install, console / scheduled context with no panel bound, …).
     */
    public function current(): ?object;

    /**
     * The current tenant's primary key, or null. Provided separately because
     * many call sites only need the id and shouldn't have to materialize the
     * full model.
     */
    public function currentId(): ?int;

    /**
     * Resolve the tenant slug for an arbitrary user — used by the dispatcher
     * to stamp `tenant_slug` onto notification context when there's no
     * panel-bound tenant (queued job, scheduled command, tinker session).
     *
     * The shipped default reads `$user->tenant->slug`. Apps whose user
     * model exposes the slug via a different relation or a denormalised
     * column override this method (extend the default or implement the
     * contract directly).
     */
    public function slugFor(object $user): ?string;
}
