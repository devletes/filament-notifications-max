<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Composed admin broadcast. Persisted at compose time; delivered by
 * {@see \Devletes\NotificationsMax\Jobs\SendBroadcastJob} which resolves
 * audience via the {@see \Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver}
 * contract and fans out one `broadcast.admin_custom` notification per recipient.
 */
class BroadcastNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'created_by',
        'subject',
        'body',
        'channels',
        'audience',
        'icon',
        'color',
        'action_url',
        'action_label',
        'status',
        'scheduled_at',
        'sent_at',
        'recipients_count',
    ];

    protected $casts = [
        'channels' => 'array',
        'audience' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'recipients_count' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            (string) config('auth.providers.users.model'),
            'created_by',
        );
    }

    /**
     * True when the status is in the list of statuses that allow the Publish
     * action to fire (config `notifications-max.broadcaster.publishable_statuses`).
     * Hosts that layer an approval step add their post-approval status
     * ('approved', etc.) to that list.
     */
    public function isPublishable(): bool
    {
        $publishable = config('notifications-max.broadcaster.publishable_statuses', ['draft']);

        return in_array($this->status, $publishable, true);
    }

    /**
     * True once the broadcast has finished fanning out to recipients.
     */
    public function isSent(): bool
    {
        return $this->status === 'sent';
    }
}
