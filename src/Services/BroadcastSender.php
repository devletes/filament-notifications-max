<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Services;

use Devletes\NotificationsMax\Jobs\SendBroadcastJob;
use Devletes\NotificationsMax\Models\BroadcastNotification;

/**
 * Single entry point for actually dispatching a {@see BroadcastNotification}
 * to its recipients.
 *
 * Both the default {@see \Devletes\NotificationsMax\Defaults\ImmediateBroadcastReleasePipeline}
 * and host-app pipelines (after an approval clears, a schedule fires, etc.)
 * call into this service. Keeping the queue-dispatch plumbing in one place
 * means the choice between "send now" and "send at scheduled_at" is resolved
 * identically regardless of which pipeline is in play.
 *
 * What this does:
 *   - Transitions the broadcast to `scheduled` (future scheduled_at) or
 *     `queued` (immediate) status so list views reflect reality between
 *     the click and the queue worker picking up the row.
 *   - Dispatches {@see SendBroadcastJob} — with `->delay()` when scheduled,
 *     immediately otherwise.
 *
 * What this does NOT do:
 *   - Run the release pipeline. That is the caller's job; by the time a
 *     pipeline hands off to the sender, it has already decided to release.
 *   - Fan out to recipients. The queue job does that.
 */
final class BroadcastSender
{
    /**
     * Transition the broadcast to its in-flight status and queue the
     * fan-out job. Safe to call from inside a web request (sync work is
     * minimal — one UPDATE + one queue push).
     */
    public function send(BroadcastNotification $broadcast): void
    {
        if ($broadcast->scheduled_at !== null && $broadcast->scheduled_at->isFuture()) {
            $broadcast->update(['status' => 'scheduled']);

            // Delay until the scheduled moment — no separate cron sweep
            // needed. Requires a queue driver that supports delays (database,
            // redis, sqs). The sync driver ignores the delay and dispatches
            // immediately, which is fine for local testing.
            dispatch(new SendBroadcastJob($broadcast))->delay($broadcast->scheduled_at);

            return;
        }

        $broadcast->update(['status' => 'queued']);

        dispatch(new SendBroadcastJob($broadcast));
    }
}
