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
 * Pure fan-out: takes a released {@see BroadcastNotification} and spreads it
 * to its recipients.
 *
 * This job holds no business logic — no pipeline calls, no permission
 * checks, no decisions about whether the broadcast should go out. By the
 * time a row reaches here its status is either `queued` (immediate release)
 * or `scheduled` (time-deferred release); anything else means the row was
 * cancelled, already sent, or otherwise not appropriate to dispatch, and
 * the job exits early.
 *
 * Pipeline inside this job:
 *   1. Reload the row to guard against stale state (retries, deletion,
 *      cancellation) between queueing and execution.
 *   2. Restore tenant context for the worker — Filament's tenant facade
 *      isn't bound from HTTP in queue worker processes.
 *   3. Chunk the audience query (100 users at a time) and dispatch a
 *      `broadcast.admin_custom` notification per chunk via the
 *      NotificationDispatcher.
 *   4. Stamp `sent_at`, `status='sent'`, and `recipients_count` on the row.
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
        // Reload to pick up cancellations / status changes that happened
        // after this job was queued.
        $broadcast = $this->broadcast->fresh();

        if ($broadcast === null) {
            return;
        }

        // Status guard. Only release states are valid entry points; anything
        // else (draft, pending_approval, rejected, sent, …) means the row
        // shouldn't be dispatched right now.
        if (! in_array($broadcast->status, ['queued', 'scheduled'], true)) {
            return;
        }

        if ($broadcast->tenant_id !== null) {
            $tenantResolver->bindForJob((int) $broadcast->tenant_id);
        }

        try {
            $context = $this->buildContext($broadcast);

            $totalRecipients = 0;

            // Chunk size is configurable so installs with very large audiences
            // can tune memory + query frequency. Falls back to 100 (the historical
            // default) when the config key is missing or non-numeric.
            $chunkSize = max(1, (int) config('notifications-max.broadcaster.chunk_size', 100));

            $broadcast
                ->newQuery()
                ->getConnection()
                ->transaction(function () use ($broadcast, $audience, $dispatcher, $context, $chunkSize, &$totalRecipients): void {
                    // Load the full user model — `routeNotificationForMail()` (and
                    // any host-added per-channel routing methods) reads attributes
                    // off the model, so restricting columns would break the mail
                    // channel and any custom channel that depends on additional
                    // user attributes.
                    $audience
                        ->matchingUsersQuery($broadcast->audience ?? [], $broadcast->tenant_id)
                        ->chunkById($chunkSize, function ($chunk) use ($dispatcher, $context, &$totalRecipients): void {
                            if ($chunk->isEmpty()) {
                                return;
                            }

                            $dispatcher->send('broadcast.admin_custom', $context, $chunk);

                            $totalRecipients += $chunk->count();
                        });

                    $broadcast->update([
                        'status' => 'sent',
                        'sent_at' => now(),
                        'recipients_count' => $totalRecipients,
                    ]);
                });
        } finally {
            // Always tear down tenant context so a long-running queue worker
            // doesn't leak the broadcast's tenant into the next job it picks
            // up. Pairs with the bindForJob call above; safe to invoke even
            // when bindForJob was skipped (single-tenant installs).
            $tenantResolver->unbindForJob();
        }
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
