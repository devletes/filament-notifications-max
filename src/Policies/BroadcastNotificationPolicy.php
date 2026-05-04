<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Policies;

use Devletes\NotificationsMax\Models\BroadcastNotification;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Gates every action on the broadcast-notification resource behind a single
 * Spatie permission, configurable via `notifications-max.broadcaster.permission`
 * (default `'broadcast-notifications'`).
 *
 * This intentionally collapses viewAny/view/create/update/delete into one gate
 * — broadcasting is a privileged operation and splitting permissions adds
 * friction without a corresponding security benefit. Consumers who want
 * finer-grained control can bind their own policy in AppServiceProvider.
 */
class BroadcastNotificationPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $this->canBroadcast($user);
    }

    public function view(Authenticatable $user, BroadcastNotification $broadcast): bool
    {
        return $this->canBroadcast($user);
    }

    public function create(Authenticatable $user): bool
    {
        return $this->canBroadcast($user);
    }

    public function update(Authenticatable $user, BroadcastNotification $broadcast): bool
    {
        // Once sent, a broadcast is historical — nobody edits it.
        if ($broadcast->isSent()) {
            return false;
        }

        return $this->canBroadcast($user);
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

        return $this->canBroadcast($user);
    }

    public function deleteAny(Authenticatable $user): bool
    {
        return $this->canBroadcast($user);
    }

    /**
     * Gate for the Publish header action on the edit page. Requires both the
     * general broadcaster permission and a status listed as publishable in
     * `notifications-max.broadcaster.publishable_statuses`. The default set
     * is `['draft']`; approval-gated installs extend it with `'approved'`.
     */
    public function publish(Authenticatable $user, BroadcastNotification $broadcast): bool
    {
        return $this->canBroadcast($user) && $broadcast->isPublishable();
    }

    protected function canBroadcast(Authenticatable $user): bool
    {
        $permission = config('notifications-max.broadcaster.permission', 'broadcast-notifications');

        if (! is_string($permission) || $permission === '') {
            return false;
        }

        // Use Spatie's `can()` via the trait if the user has it; fall back to
        // the framework gate so hosts without Spatie can still bind their own
        // authorization mechanism via Laravel Gate.
        if (method_exists($user, 'can')) {
            return (bool) $user->can($permission);
        }

        return false;
    }
}
