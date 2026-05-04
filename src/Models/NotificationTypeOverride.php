<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-(tenant, type) override row.
 *
 * Active only when `notifications-max.content_source = 'database'`.
 * Config mode ignores this model entirely — the resolver short-circuits
 * before any query runs.
 *
 * The two JSON columns (`allowed_channels`, `channel_content`) are
 * deliberately schema-light: the channel registry (config) is the source
 * of truth for which channels exist and what fields each one accepts.
 * That keeps adding a new channel a config change rather than a migration.
 */
class NotificationTypeOverride extends Model
{
    protected $fillable = [
        'tenant_id',
        'type_key',
        'allowed_channels',
        'channel_content',
    ];

    protected $casts = [
        'allowed_channels' => 'array',
        'channel_content' => 'array',
    ];

    /**
     * Look up the override row for a given tenant + type. Returns null
     * when no override exists; resolver falls back to config in that case.
     */
    public static function lookup(?int $tenantId, string $typeKey): ?self
    {
        return static::query()
            ->where('tenant_id', $tenantId)
            ->where('type_key', $typeKey)
            ->first();
    }
}
