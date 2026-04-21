<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * Decides who is permitted to operate the admin broadcaster and who counts
 * as an "admin" for purposes of recipient resolution (e.g. CC'ing HR admins
 * on a termination notification).
 *
 * The package ships a default that uses spatie/laravel-permission when it is
 * installed and no-ops otherwise. Host apps with their own permission layer
 * bind their own implementation.
 */
interface AdminRoleResolver
{
    /**
     * Authorize a user to compose and send admin broadcasts. Used by the
     * BroadcastNotificationResource policy. Returning false hides the
     * admin broadcaster nav + routes for this user.
     */
    public function canBroadcast(Authenticatable $user): bool;

    /**
     * Return all admins for a given tenant (null tenant = global) optionally
     * filtered to a specific role name (e.g. "hr_manager"). Used by domain
     * dispatch sites that want to notify "all HR admins in this tenant".
     */
    public function adminsForTenant(?int $tenantId, ?string $role = null): Collection;
}
