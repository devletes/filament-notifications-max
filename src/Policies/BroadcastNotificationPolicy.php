<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Policies;

use Devletes\NotificationsMax\Models\BroadcastNotification;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Gates broadcast-notification resource actions via either:
 *
 *   (1) A per-action permission map at
 *       `notifications-max.broadcaster.permissions` — keyed by policy
 *       method (`view_any`, `view`, `create`, `update`, `delete`). This
 *       is the default and matches Filament Shield's auto-generated
 *       permission naming, so running `php artisan shield:generate` and
 *       syncing super_admin lights everything up out of the box.
 *
 *   (2) A single fallback permission name at
 *       `notifications-max.broadcaster.permission` — used when the map
 *       above is unset / empty. Provided for installs that gate all
 *       broadcast actions behind one permission rather than Shield's
 *       per-action convention.
 *
 * Lifecycle invariants are enforced before the permission check:
 *
 *   - update() refuses sent broadcasts (historical record).
 *   - delete() / deleteAny() refuse anything past the initial status
 *     so audit context is preserved post-release.
 *   - publish() additionally requires {@see BroadcastNotification::isPublishable()},
 *     which reads the configurable list of publishable statuses.
 */
class BroadcastNotificationPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $this->checkPermission($user, 'view_any');
    }

    public function view(Authenticatable $user, BroadcastNotification $broadcast): bool
    {
        return $this->checkPermission($user, 'view');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->checkPermission($user, 'create');
    }

    public function update(Authenticatable $user, BroadcastNotification $broadcast): bool
    {
        // Once sent, a broadcast is historical — nobody edits it.
        if ($broadcast->isSent()) {
            return false;
        }

        return $this->checkPermission($user, 'update');
    }

    public function delete(Authenticatable $user, BroadcastNotification $broadcast): bool
    {
        // Delete is a pre-release affordance only. Once the broadcast has
        // left the initial status (queued / scheduled / sent / host
        // workflow states), the row is part of a delivery audit trail
        // and removing it would lose context — hide both the UI action
        // and gate the underlying policy call so direct URL access is
        // also refused.
        $initialStatus = config('notifications-max.broadcaster.initial_status', 'draft');

        if ($broadcast->status !== $initialStatus) {
            return false;
        }

        return $this->checkPermission($user, 'delete');
    }

    public function deleteAny(Authenticatable $user): bool
    {
        return $this->checkPermission($user, 'delete');
    }

    /**
     * Gate for the Publish header action on the edit page. Requires both
     * the update permission and a publishable status. Approval-gated
     * installs extend the publishable list (config
     * `notifications-max.broadcaster.publishable_statuses`) with their own
     * post-approval status.
     */
    public function publish(Authenticatable $user, BroadcastNotification $broadcast): bool
    {
        if (! $broadcast->isPublishable()) {
            return false;
        }

        return $this->checkPermission($user, 'update');
    }

    /**
     * Resolve the configured permission name for a policy action and
     * delegate to `$user->can()`. Reads from the per-action `permissions`
     * map first and falls back to the legacy single-permission config.
     *
     * Returns false when neither config is populated, when the resolved
     * name is empty, or when the user model doesn't expose `can()`. The
     * last branch lets the policy stay safe in odd test contexts where
     * the user model lacks the standard authorization helpers.
     */
    protected function checkPermission(Authenticatable $user, string $action): bool
    {
        $permission = $this->permissionFor($action);

        if ($permission === null) {
            return false;
        }

        return method_exists($user, 'can') ? (bool) $user->can($permission) : false;
    }

    protected function permissionFor(string $action): ?string
    {
        $map = config('notifications-max.broadcaster.permissions');

        if (is_array($map) && isset($map[$action]) && is_string($map[$action]) && $map[$action] !== '') {
            return $map[$action];
        }

        $single = config('notifications-max.broadcaster.permission');

        return is_string($single) && $single !== '' ? $single : null;
    }
}
