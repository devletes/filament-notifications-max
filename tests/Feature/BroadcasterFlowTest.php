<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Devletes\NotificationsMax\Contracts\BroadcastReleasePipeline;
use Devletes\NotificationsMax\Jobs\SendBroadcastJob;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
use Devletes\NotificationsMax\Tests\Stubs\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Bus;

/**
 * End-to-end smoke test for the broadcaster: from a draft row in the
 * database to per-recipient notification rows persisted via the database
 * channel. Walks the same path a real admin click on the Publish action
 * triggers — release pipeline transitions the row, queues the job, the
 * job fans out via the dispatcher and content resolver.
 */
beforeEach(function (): void {
    config(['notifications' => array_merge(config('notifications', []), [
        'broadcast.admin_custom' => [
            'category' => 'announcements',
            'title' => '{subject}',
            'body' => '{body}',
            'icon' => 'heroicon-o-megaphone',
            'default_channels' => ['push'],
            'allowed_channels' => ['push', 'email'],
        ],
    ])]);
    app(NotificationTypeRegistry::class)->flush();
});

it('publish-then-run-job: draft broadcast becomes sent and recipients have notification rows', function (): void {
    // Three test recipients.
    $recipients = collect([
        User::query()->create(['email' => 'a@x.test']),
        User::query()->create(['email' => 'b@x.test']),
        User::query()->create(['email' => 'c@x.test']),
    ]);

    // An admin composes a broadcast (mirrors what
    // CreateBroadcastNotification's mutateFormDataBeforeCreate does).
    $broadcast = BroadcastNotification::query()->create([
        'tenant_id' => null,
        'subject' => 'Quarterly update',
        'body' => 'Q3 numbers attached',
        'channels' => ['push'],
        'audience' => ['user_ids' => $recipients->pluck('id')->all()],
        'status' => 'draft',
    ]);

    // Step 1 — admin clicks Publish. The release pipeline transitions
    // status and queues the fan-out job. We capture the dispatch via
    // Bus::fake here so we can assert the queueing and then run the job
    // manually below.
    Bus::fake([SendBroadcastJob::class]);

    $result = app(BroadcastReleasePipeline::class)->handle($broadcast);

    expect($result->title)->toBe('Broadcast queued')
        ->and($broadcast->fresh()->status)->toBe('queued');

    Bus::assertDispatched(SendBroadcastJob::class);

    // Step 2 — run the queued job exactly as a worker would. Bus::fake
    // intercepted the dispatch, so call handle() directly.
    (new SendBroadcastJob($broadcast->fresh()))->handle(
        app(BroadcastAudienceResolver::class),
        app(NotificationDispatcher::class),
    );

    // Step 3 — verify the broadcast row reached terminal state and every
    // recipient has a persisted notification row.
    $finalBroadcast = $broadcast->fresh();
    expect($finalBroadcast->status)->toBe('sent')
        ->and($finalBroadcast->sent_at)->not->toBeNull()
        ->and($finalBroadcast->recipients_count)->toBe(3);

    $notifications = DatabaseNotification::query()->get();
    expect($notifications)->toHaveCount(3);

    foreach ($recipients as $recipient) {
        $row = $notifications->firstWhere('notifiable_id', $recipient->id);
        expect($row)->not->toBeNull()
            ->and($row->data['_meta']['type_key'] ?? null)->toBe('broadcast.admin_custom')
            ->and($row->data['title'] ?? null)->toBe('Quarterly update')
            // The broadcast_id is stamped on the payload so the
            // audience-relation-manager's read/unread subquery can
            // find these rows by broadcast.
            ->and($row->data['broadcast_id'] ?? null)->toBe($finalBroadcast->getKey());
    }
});
