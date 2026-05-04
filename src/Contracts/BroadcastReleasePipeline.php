<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Contracts;

use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\Support\BroadcastReleasePrompt;
use Devletes\NotificationsMax\Support\BroadcastReleaseResult;

/**
 * Decides what happens when an admin clicks the Publish action on a broadcast.
 *
 * Runs synchronously inside the Filament action lifecycle — no queue, no
 * background work. Whatever the pipeline does, it must be safe to do in the
 * web request (DB writes are fine; long fan-out is not — delegate that to
 * {@see \Devletes\NotificationsMax\Services\BroadcastSender} which in turn
 * dispatches the queue job).
 *
 * Two mental models for implementations:
 *
 *   "Just send it" (shipped default) — transitions the broadcast to the
 *   appropriate lifecycle status (queued / scheduled) and hands off to the
 *   sender. The queue worker completes the fan-out.
 *
 *   "Gate it" (host apps) — captures the release intent in whatever host-app
 *   workflow applies (approval ticket, moderation queue, policy check) and
 *   transitions the broadcast to a waiting status. The dispatch job is never
 *   queued from here. When the gate clears, the host calls
 *   {@see \Devletes\NotificationsMax\Services\BroadcastSender::send()}
 *   directly — same entry point the default pipeline uses — to actually send.
 *
 * The returned {@see BroadcastReleaseResult} drives the Filament toast that
 * confirms the action back to the admin. Use it to describe what just
 * happened ("Submitted for approval", "Broadcast queued", etc.) — not what
 * the admin did.
 */
interface BroadcastReleasePipeline
{
    public function handle(BroadcastNotification $broadcast): BroadcastReleaseResult;

    /**
     * Describe the Publish action's label + confirmation for the given
     * broadcast state. The resource view page asks the pipeline how to
     * present the button BEFORE the admin clicks it — so an approval-gated
     * pipeline can show "Submit for Approval" on a draft and "Publish" on
     * an approved row, while an immediate pipeline always shows "Publish".
     */
    public function describeAction(BroadcastNotification $broadcast): BroadcastReleasePrompt;
}
