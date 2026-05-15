<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Queue;

use Closure;
use Filament\Facades\Filament;
use Filament\Panel;

/**
 * Laravel job middleware that restores Filament tenant context (and, when
 * Spatie permission's `teams` feature is enabled, the active team id)
 * inside a queue worker process.
 *
 * The host doesn't need to write any tenancy-aware glue code: this
 * middleware introspects Filament's panel registry at runtime to find
 * the tenanted panel, hydrates the tenant from its declared model, and
 * binds everything. The job just needs a public `?int $tenantId`
 * property and a `middleware()` method that returns this class.
 *
 * Attach via Laravel's standard job middleware mechanism:
 *
 *   public ?int $tenantId;
 *
 *   public function middleware(): array
 *   {
 *       return [app(RestoreTenantContext::class)];
 *   }
 *
 * The `$tenantId` property name is the same one
 * {@link https://github.com/ultraviolettes/filament-jobs-monitor ultraviolettes/filament-jobs-monitor}
 * reads for its tenant-scoped job dashboards, so jobs that opt into
 * this middleware also get tenant-filtered visibility in that package
 * when it's installed — same property, dual purpose.
 *
 * Multi-panel installs where more than one panel has tenancy (rare) can
 * disambiguate via `notifications-max.tenant.panel` config. When omitted,
 * the first panel found in `Filament::getPanels()` with a tenant model
 * declared wins.
 *
 * Always restores the previous tenant / panel / team in a `finally`
 * block, so a long-running worker doesn't leak the broadcast's tenant
 * into the next job it picks up.
 */
class RestoreTenantContext
{
    /**
     * Memoised panel-discovery result. Filament's panel registry doesn't
     * change during a worker's lifetime, so we walk it once per process
     * rather than once per job.
     */
    protected static ?Panel $discoveredPanel = null;

    protected static bool $panelDiscoveryAttempted = false;

    public function handle(object $job, Closure $next): mixed
    {
        $tenantId = $this->tenantIdFor($job);

        if ($tenantId === null) {
            return $next($job);
        }

        $panel = $this->resolvePanel();

        if ($panel === null) {
            return $next($job);
        }

        $tenantModel = $panel->getTenantModel();

        if ($tenantModel === null) {
            return $next($job);
        }

        $tenant = $tenantModel::query()->find($tenantId);

        if ($tenant === null) {
            return $next($job);
        }

        // Snapshot the state we're about to mutate. Typically null in a
        // queue worker, but the snapshot makes us safe under nested
        // dispatches and inside tests that swap context mid-run.
        $previousPanel = Filament::getCurrentPanel();
        $previousTenant = Filament::getTenant();
        $spatieTeamsEnabled = $this->spatieTeamsEnabled();
        $previousTeamId = $spatieTeamsEnabled ? $this->currentSpatieTeamId() : null;

        try {
            Filament::setCurrentPanel($panel);
            // `isQuiet: true` — TenantSet listeners typically expect HTTP
            // context (auth user available, session bound, etc.) and throw
            // when invoked from a queue worker.
            Filament::setTenant($tenant, isQuiet: true);

            if ($spatieTeamsEnabled) {
                $this->setSpatieTeamId($tenantId);
            }

            return $next($job);
        } finally {
            Filament::setCurrentPanel($previousPanel);
            Filament::setTenant($previousTenant, isQuiet: true);

            if ($spatieTeamsEnabled) {
                $this->setSpatieTeamId($previousTeamId);
            }
        }
    }

    /**
     * Reset the cached panel lookup. Provided for tests that register
     * panels mid-run; production code shouldn't need to call this.
     */
    public static function flushPanelCache(): void
    {
        static::$discoveredPanel = null;
        static::$panelDiscoveryAttempted = false;
    }

    /**
     * Read the `tenantId` property off the job. Public + nullable int is
     * the contract — jobs that don't declare it, or carry a non-numeric
     * value, are left to run without any tenant binding.
     */
    protected function tenantIdFor(object $job): ?int
    {
        if (! property_exists($job, 'tenantId')) {
            return null;
        }

        /** @phpstan-ignore-next-line dynamic property access on a contract-shaped value */
        $value = $job->tenantId;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Locate the panel whose tenant context we're going to bind. Honours
     * an explicit `tenant.panel` config when set; otherwise picks the
     * first registered panel with a tenant model declared. Cached on the
     * class so we don't re-walk the panel list on every job.
     */
    protected function resolvePanel(): ?Panel
    {
        if (static::$panelDiscoveryAttempted) {
            return static::$discoveredPanel;
        }

        static::$panelDiscoveryAttempted = true;

        $configuredId = config('notifications-max.tenant.panel');

        if (is_string($configuredId) && $configuredId !== '') {
            $panel = Filament::getPanel($configuredId, isStrict: false);

            if ($panel !== null && $panel->getTenantModel() !== null) {
                return static::$discoveredPanel = $panel;
            }
        }

        foreach (Filament::getPanels() as $panel) {
            if ($panel->getTenantModel() !== null) {
                return static::$discoveredPanel = $panel;
            }
        }

        return null;
    }

    protected function spatieTeamsEnabled(): bool
    {
        return app()->bound(\Spatie\Permission\PermissionRegistrar::class)
            && config('permission.teams') === true;
    }

    protected function currentSpatieTeamId(): ?int
    {
        $id = app(\Spatie\Permission\PermissionRegistrar::class)->getPermissionsTeamId();

        return is_numeric($id) ? (int) $id : null;
    }

    protected function setSpatieTeamId(?int $teamId): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($teamId);
    }
}
