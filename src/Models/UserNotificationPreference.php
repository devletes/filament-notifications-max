<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's explicit preference row for a single (notification_type_key,
 * channel) tuple. Absence of a row for a tuple means "use the type's
 * default_channels".
 *
 * Multi-tenant installs populate `tenant_id` via the package's
 * NotificationTenantObserver (or equivalent host-bound resolver). Single-
 * tenant installs leave it null — that's fine because user_id is already
 * implicitly tenant-scoped in those setups.
 */
class UserNotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'notification_type_key',
        'channel',
        'enabled',
        'tenant_id',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    /**
     * Upsert a single preference row. Convenience used by the preferences
     * Filament page's save handler.
     */
    public static function set(
        int|string $userId,
        string $typeKey,
        string $channel,
        bool $enabled,
        ?int $tenantId = null,
    ): self {
        return static::updateOrCreate(
            [
                'user_id' => $userId,
                'notification_type_key' => $typeKey,
                'channel' => $channel,
            ],
            [
                'enabled' => $enabled,
                'tenant_id' => $tenantId,
            ],
        );
    }
}
