<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Jobs;

use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\Queue\RestoreTenantContext;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

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
 *   2. Resume from `last_processed_id` (the highest user id this broadcast
 *      has already fanned out to). On the first attempt this is null and
 *      the resume `where` is a no-op; on a retried attempt it skips chunks
 *      that already delivered so recipients don't get duplicates.
 *   3. Chunk the audience query (configurable via `broadcaster.chunk_size`,
 *      default 100) and dispatch a `broadcast.admin_custom` notification
 *      per chunk via the NotificationDispatcher. After each chunk, atomic
 *      UPDATE bumps `last_processed_id` and increments `recipients_count`
 *      — no outer transaction, so chunk progress is durable mid-run.
 *   4. When chunkById exhausts, stamp `sent_at` and `status='sent'` on
 *      the row.
 *
 * Tenant context: handled by {@see RestoreTenantContext} job middleware,
 * driven off the public `$tenantId` property declared below. The middleware
 * binds Filament's tenant + (optionally) Spatie's permission team before
 * `handle()` runs and tears them down in a `finally` afterwards, so the
 * job code can stay focused on fan-out logic.
 *
 * Failure mode: if a chunk's dispatch succeeds but the cursor update
 * fails (DB connection dropped between the two), a retry replays that
 * chunk's recipients. The reverse ordering would under-deliver instead,
 * which is the worse failure mode — duplicate notifications are visible
 * and recoverable; silently-dropped ones are not.
 */
class SendBroadcastJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Tenant the job is dispatched on behalf of. Read by
     * {@see RestoreTenantContext} (the middleware that binds Filament's
     * tenant inside the worker) and by `ultraviolettes/filament-jobs-monitor`
     * (if installed) for tenant-scoped job dashboards. Same property,
     * two consumers.
     */
    public ?int $tenantId;

    public function __construct(public BroadcastNotification $broadcast)
    {
        $this->tenantId = $broadcast->tenant_id !== null ? (int) $broadcast->tenant_id : null;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [app(RestoreTenantContext::class)];
    }

    public function handle(
        BroadcastAudienceResolver $audience,
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

        $context = $this->buildContext($broadcast);

        // Chunk size is configurable so installs with very large audiences
        // can tune memory + query frequency. Falls back to 100 (the historical
        // default) when the config key is missing or non-numeric.
        $chunkSize = max(1, (int) config('notifications-max.broadcaster.chunk_size', 100));

        // Resume from the highest already-fanned-out user id. On the first
        // attempt last_processed_id is null and the where() is omitted; on
        // a retried attempt it skips chunks that already delivered so
        // recipients don't get duplicates.
        $query = $audience->matchingUsersQuery($broadcast->audience ?? [], $broadcast->tenant_id);

        if ($broadcast->last_processed_id !== null) {
            $query->where($query->getModel()->getQualifiedKeyName(), '>', $broadcast->last_processed_id);
        }

        // Load the full user model — `routeNotificationForMail()` (and any
        // host-added per-channel routing methods) reads attributes off the
        // model, so restricting columns would break the mail channel and
        // any custom channel that depends on additional user attributes.
        //
        // No outer transaction here. Holding row-level locks for the entire
        // chunk loop turns large-audience broadcasts into long-running
        // transactions that contend with everything else writing to the
        // notifications and broadcast_notifications tables. Per-chunk
        // updates are atomic on their own.
        $query->chunkById($chunkSize, function (\Illuminate\Database\Eloquent\Collection $chunk) use ($broadcast, $dispatcher, $context): void {
            if ($chunk->isEmpty()) {
                return;
            }

            // Dispatch FIRST, then advance the cursor. The reverse order
            // (cursor first, dispatch second) under-delivers if dispatch
            // fails after the cursor commits. The chosen order over-
            // delivers in the same edge case (cursor update fails after
            // dispatch succeeded) — a retried chunk re-sends to recipients
            // who already got notified. Duplicate notifications are
            // visible and self-correcting; silently-dropped ones are not.
            $dispatcher->send('broadcast.admin_custom', $context, $chunk);

            $broadcast->newQuery()
                ->whereKey($broadcast->getKey())
                ->update([
                    'last_processed_id' => $chunk->last()->getKey(),
                    'recipients_count' => DB::raw('COALESCE(recipients_count, 0) + '.(int) $chunk->count()),
                ]);
        });

        // Final stamp outside the chunk loop. A retried run that resumes
        // mid-fan-out won't accidentally mark "sent" early — the status
        // flips only when chunkById exhausts.
        $broadcast->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
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
