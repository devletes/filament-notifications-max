<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Observers;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;

/**
 * Populates the `tenant_id` column on the `notifications` table at insert time,
 * sourced from the notifiable's own `tenant_id` attribute.
 *
 * This is a multi-tenancy backstop: `notifiable_id` already implies a tenant
 * because each user belongs to one, but the explicit column enables direct
 * tenant-scoped queries and prevents cross-tenant bugs in batch jobs.
 *
 * The observer is a no-op on single-tenant installs where the column doesn't
 * exist, so it is always safe to register.
 */
class NotificationTenantObserver
{
    /**
     * Cache the column check so we only hit the schema once per request.
     */
    protected static ?bool $tenantColumnExists = null;

    public function creating(DatabaseNotification $notification): void
    {
        if (! $this->tenantColumnExists($notification)) {
            return;
        }

        if ($notification->getAttribute('tenant_id') !== null) {
            return;
        }

        $notifiable = $notification->notifiable;

        if ($notifiable && isset($notifiable->tenant_id)) {
            $notification->setAttribute('tenant_id', $notifiable->tenant_id);
        }
    }

    protected function tenantColumnExists(DatabaseNotification $notification): bool
    {
        if (static::$tenantColumnExists !== null) {
            return static::$tenantColumnExists;
        }

        return static::$tenantColumnExists = Schema::hasColumn(
            $notification->getTable(),
            'tenant_id',
        );
    }
}
