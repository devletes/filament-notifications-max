<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Contracts;

/**
 * Abstracts the host app's tenant context so the package itself can run in
 * both multi-tenant and single-tenant installs without hard-coding Filament's
 * tenant facade or any ORM.
 *
 * The package ships:
 *   - NullTenantResolver (default for single-tenant; always returns null)
 *   - The host app binds a Filament-aware resolver when running multi-tenant.
 */
interface TenantResolver
{
    /**
     * The current tenant model, or null if single-tenant / not in a tenant
     * context (e.g. console panel, tests, scheduled commands).
     */
    public function current(): ?object;

    /**
     * The current tenant's primary key, or null. Provided separately because
     * many call sites only need the id and shouldn't have to materialize the
     * full model.
     */
    public function currentId(): ?int;

    /**
     * Resolve the tenant slug for an arbitrary user (the typical fallback
     * when the dispatcher is invoked outside an HTTP request, e.g. from
     * a queued job, scheduled command, or `php artisan tinker`).
     *
     * Implementations that follow the conventional `User->tenant->slug`
     * relationship can extend {@see \Devletes\NotificationsMax\Defaults\NullTenantResolver}
     * to inherit a sensible default. Apps with a different relationship
     * shape (multi-tenant pivot, denormalised slug column, lookup service,
     * etc.) implement this directly.
     */
    public function slugFor(object $user): ?string;

    /**
     * Restore tenant context inside a queued job or scheduled command that
     * is not running through the HTTP lifecycle. Host apps typically call
     * Filament::setTenant($tenant) or equivalent in their implementation.
     */
    public function bindForJob(int $tenantId): void;

    /**
     * Clear any tenant context this resolver established in {@see bindForJob()}.
     *
     * Long-running queue workers, scheduled commands, and Octane processes
     * pull jobs from a shared queue: without an explicit teardown, the
     * tenant bound for job A leaks into job B's execution if job B doesn't
     * happen to bind its own tenant. Callers (notably {@see \Devletes\NotificationsMax\Jobs\SendBroadcastJob})
     * are expected to invoke this in a `finally` block paired with
     * `bindForJob` so the worker returns to a clean slate.
     *
     * Implementations should be idempotent — calling without a prior
     * `bindForJob` must not error. {@see \Devletes\NotificationsMax\Defaults\NullTenantResolver}
     * is a no-op; single-tenant installs need no teardown.
     */
    public function unbindForJob(): void;
}
