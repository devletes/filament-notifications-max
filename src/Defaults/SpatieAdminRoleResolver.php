<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Defaults;

use Devletes\NotificationsMax\Contracts\AdminRoleResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * Default implementation that uses spatie/laravel-permission when it is
 * installed. Soft-dependency: if Spatie is absent the methods degrade to
 * sensible no-ops so the package still boots.
 *
 * The "admin" concept is intentionally loose: host apps either bind a richer
 * resolver (e.g. HRMS checks the `Send:BroadcastNotification` permission and
 * specific role sets) or configure the role name used here via the package
 * config: `notifications-max.admin_role`.
 */
class SpatieAdminRoleResolver implements AdminRoleResolver
{
    public function canBroadcast(Authenticatable $user): bool
    {
        // Prefer the explicit permission if Spatie's HasRoles is present on the user.
        if (method_exists($user, 'can')) {
            $permission = config('notifications-max.broadcaster.permission', 'broadcast-notifications');

            if ($user->can($permission)) {
                return true;
            }
        }

        if (method_exists($user, 'hasRole')) {
            $role = config('notifications-max.admin_role', 'admin');

            return (bool) $user->hasRole($role);
        }

        return false;
    }

    public function adminsForTenant(?int $tenantId, ?string $role = null): Collection
    {
        $userClass = config('auth.providers.users.model');

        if (! $userClass || ! class_exists($userClass)) {
            return new Collection;
        }

        $query = $userClass::query();

        // Tenant scoping — if the user model has a `tenant_id` column, filter to it.
        if ($tenantId !== null && $this->modelHasTenantIdColumn($userClass)) {
            $query->where('tenant_id', $tenantId);
        }

        // Spatie role filter — only apply if the role method is available.
        $roleName = $role ?? config('notifications-max.admin_role', 'admin');

        if (method_exists($userClass, 'hasRole')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $roleName));
        }

        return $query->get();
    }

    protected function modelHasTenantIdColumn(string $model): bool
    {
        try {
            $instance = new $model;
            $table = $instance->getTable();

            return \Illuminate\Support\Facades\Schema::hasColumn($table, 'tenant_id');
        } catch (\Throwable) {
            return false;
        }
    }
}
