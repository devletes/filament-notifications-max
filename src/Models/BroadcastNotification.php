<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Composed admin broadcast. Persisted at compose time; delivered by
 * {@see \Devletes\NotificationsMax\Jobs\SendBroadcastJob} which resolves
 * audience via the {@see \Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver}
 * contract and fans out one `broadcast.admin_custom` notification per recipient.
 *
 * UUID primary key to match the conventions of Laravel's `notifications`
 * table and avoid exposing sequential ids in URLs.
 */
class BroadcastNotification extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

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
     * True iff the broadcast has not yet been dispatched to recipients.
     * Used by the Filament resource to show "Send now" / "Edit" actions.
     */
    public function isPending(): bool
    {
        return $this->sent_at === null;
    }

    public function isScheduled(): bool
    {
        return $this->isPending() && $this->scheduled_at !== null;
    }
}
