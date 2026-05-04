<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Defaults;

use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Devletes\NotificationsMax\Contracts\BroadcastReleasePipeline;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\Services\BroadcastSender;
use Devletes\NotificationsMax\Support\BroadcastReleasePrompt;
use Devletes\NotificationsMax\Support\BroadcastReleaseResult;

/**
 * Default {@see BroadcastReleasePipeline} — no gatekeeping, just send.
 *
 * Hands the broadcast straight to {@see BroadcastSender::send()}, which
 * transitions the row to `scheduled` or `queued` and dispatches the
 * {@see \Devletes\NotificationsMax\Jobs\SendBroadcastJob}. The returned
 * result describes what happened so the Filament toast is accurate even
 * when the broadcast is scheduled rather than sent immediately.
 *
 * Host apps that need an approval step or any pre-send workflow bind their
 * own implementation of {@see BroadcastReleasePipeline} via
 * `notifications-max.broadcaster.release_pipeline` — that implementation
 * takes the broadcast, parks it in whatever waiting status applies, and
 * later (once cleared) calls {@see BroadcastSender::send()} directly. Same
 * dispatch entry point either way.
 */
final class ImmediateBroadcastReleasePipeline implements BroadcastReleasePipeline
{
    public function __construct(
        protected BroadcastSender $sender,
        protected BroadcastAudienceResolver $audience,
    ) {}

    public function handle(BroadcastNotification $broadcast): BroadcastReleaseResult
    {
        $scheduled = $broadcast->scheduled_at !== null && $broadcast->scheduled_at->isFuture();

        $this->sender->send($broadcast);

        if ($scheduled) {
            return new BroadcastReleaseResult(
                title: 'Broadcast scheduled',
                body: 'Delivery will begin at ' . $broadcast->scheduled_at->format('M j, Y H:i') . '.',
            );
        }

        return new BroadcastReleaseResult(
            title: 'Broadcast queued',
            body: 'Delivery has started.',
        );
    }

    public function describeAction(BroadcastNotification $broadcast): BroadcastReleasePrompt
    {
        $scheduled = $broadcast->scheduled_at !== null && $broadcast->scheduled_at->isFuture();

        $count = $this->audience->countMatching($broadcast->audience ?? [], $broadcast->tenant_id);
        $noun = $count === 1 ? 'recipient' : 'recipients';

        if ($scheduled) {
            return new BroadcastReleasePrompt(
                label: 'Schedule',
                confirmation: sprintf(
                    'This will schedule delivery to %d %s at %s. Continue?',
                    $count,
                    $noun,
                    $broadcast->scheduled_at->format('M j, Y H:i'),
                ),
                color: 'warning',
                icon: 'heroicon-o-clock',
            );
        }

        return new BroadcastReleasePrompt(
            label: 'Publish',
            confirmation: sprintf('This will notify %d %s. Continue?', $count, $noun),
        );
    }
}
