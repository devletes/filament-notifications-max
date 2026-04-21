<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Jobs;

use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Devletes\NotificationsMax\Contracts\TenantResolver;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fans out a stored {@see BroadcastNotification} to its resolved audience.
 *
 * Pipeline:
 *   1. Restore tenant context (for multi-tenant queue workers where the
 *      HTTP lifecycle isn't carrying it).
 *   2. Resolve the audience via the bound {@see BroadcastAudienceResolver}.
 *   3. Chunk the user list (100 per batch) and dispatch one
 *      `broadcast.admin_custom` per chunk through {@see NotificationDispatcher}.
 *   4. Stamp `sent_at` + `recipients_count` on the broadcast row.
 *
 * Scheduled broadcasts are dispatched with `->delay($scheduled_at)` at
 * creation time — no cron sweep needed.
 */
class SendBroadcastJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public BroadcastNotification $broadcast) {}

    public function handle(
        BroadcastAudienceResolver $audience,
        TenantResolver $tenantResolver,
        NotificationDispatcher $dispatcher,
    ): void {
        // Reload from DB — guards against the broadcast having been deleted
        // or already sent by the time the queue worker picks it up.
        $broadcast = $this->broadcast->fresh();

        if ($broadcast === null) {
            return;
        }

        if ($broadcast->sent_at !== null) {
            // Idempotency: a double-dispatch (e.g. retry after timeout)
            // shouldn't re-send to everyone.
            return;
        }

        // Multi-tenant installs need the tenant bound in the queue context
        // so downstream services (URL builders, preference resolver) see
        // the right tenant.
        if ($broadcast->tenant_id !== null) {
            $tenantResolver->bindForJob((int) $broadcast->tenant_id);
        }

        $context = $this->buildContext($broadcast);

        $totalRecipients = 0;

        $broadcast
            ->newQuery()
            // We re-use the builder each chunk; Eloquent's chunkById is
            // safer than offset-chunk against tables that may grow mid-send.
            ->getConnection()
            ->transaction(function () use ($broadcast, $audience, $dispatcher, $context, &$totalRecipients): void {
                $audience
                    ->matchingUsersQuery($broadcast->audience ?? [], $broadcast->tenant_id)
                    ->select(['id', 'tenant_id']) // minimize payload
                    ->chunkById(100, function ($chunk) use ($dispatcher, $context, &$totalRecipients): void {
                        if ($chunk->isEmpty()) {
                            return;
                        }

                        $dispatcher->send('broadcast.admin_custom', $context, $chunk);

                        $totalRecipients += $chunk->count();
                    });

                $broadcast->update([
                    'sent_at' => now(),
                    'recipients_count' => $totalRecipients,
                ]);
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildContext(BroadcastNotification $broadcast): array
    {
        $context = [
            'subject' => $broadcast->subject,
            'body' => $broadcast->body,
            'broadcast_id' => $broadcast->getKey(),
        ];

        if ($broadcast->action_url) {
            $context['action_url'] = $broadcast->action_url;
        }

        if ($broadcast->action_label) {
            $context['action_label'] = $broadcast->action_label;
        }

        return $context;
    }
}
