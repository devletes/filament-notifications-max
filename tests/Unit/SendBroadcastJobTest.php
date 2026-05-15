<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Devletes\NotificationsMax\Jobs\SendBroadcastJob;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
use Devletes\NotificationsMax\Tests\Stubs\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    // Pre-loaded broadcast type — the dispatcher uses this when fanning
    // out, so the registry needs a 'broadcast.admin_custom' entry.
    config(['notifications' => array_merge(config('notifications', []), [
        'broadcast.admin_custom' => [
            'category' => 'announcements',
            'title' => '{subject}',
            'body' => '{body}',
            'default_channels' => ['push'],
            'allowed_channels' => ['push', 'email'],
        ],
    ])]);
    app(NotificationTypeRegistry::class)->flush();

    Notification::fake();
});

/**
 * Helper to build a saved broadcast row with N recipients of the user_ids
 * audience shape, then return the [broadcast, user collection] tuple.
 *
 * @return array{0: BroadcastNotification, 1: \Illuminate\Support\Collection}
 */
function makeBroadcastWithRecipients(int $count, string $status = 'queued'): array
{
    $users = collect();

    for ($i = 0; $i < $count; $i++) {
        $users->push(User::query()->create([
            'email' => "recipient-{$i}@x.test",
        ]));
    }

    $broadcast = BroadcastNotification::query()->create([
        'tenant_id' => null,
        'subject' => 'Hello',
        'body' => 'World',
        'channels' => ['push'],
        'audience' => ['user_ids' => $users->pluck('id')->all()],
        'status' => $status,
    ]);

    return [$broadcast, $users];
}

it('refuses to dispatch when the broadcast is in draft status', function (): void {
    [$broadcast] = makeBroadcastWithRecipients(2, status: 'draft');

    (new SendBroadcastJob($broadcast))->handle(
        app(BroadcastAudienceResolver::class),
        app(NotificationDispatcher::class),
    );

    Notification::assertNothingSent();
    expect($broadcast->fresh()->status)->toBe('draft');
});

it('refuses to dispatch when the broadcast is already sent', function (): void {
    [$broadcast] = makeBroadcastWithRecipients(2, status: 'sent');

    (new SendBroadcastJob($broadcast))->handle(
        app(BroadcastAudienceResolver::class),
        app(NotificationDispatcher::class),
    );

    Notification::assertNothingSent();
});

it('fans out to every recipient and stamps status=sent / sent_at on completion', function (): void {
    [$broadcast, $users] = makeBroadcastWithRecipients(3);

    (new SendBroadcastJob($broadcast))->handle(
        app(BroadcastAudienceResolver::class),
        app(NotificationDispatcher::class),
    );

    foreach ($users as $user) {
        Notification::assertSentTo($user, GenericNotification::class);
    }

    $fresh = $broadcast->fresh();
    expect($fresh->status)->toBe('sent')
        ->and($fresh->sent_at)->not->toBeNull()
        ->and($fresh->recipients_count)->toBe(3);
});

it('tracks last_processed_id and recipients_count across chunks', function (): void {
    config(['notifications-max.broadcaster.chunk_size' => 2]);

    [$broadcast, $users] = makeBroadcastWithRecipients(5);

    (new SendBroadcastJob($broadcast))->handle(
        app(BroadcastAudienceResolver::class),
        app(NotificationDispatcher::class),
    );

    $fresh = $broadcast->fresh();
    expect($fresh->recipients_count)->toBe(5)
        ->and($fresh->last_processed_id)->toBe($users->last()->id);
});

it('resumes from last_processed_id on a retried run, skipping already-delivered users', function (): void {
    [$broadcast, $users] = makeBroadcastWithRecipients(4);

    // Simulate a partial previous attempt: first two users already
    // delivered. The resume cursor should skip them on this run.
    $firstHalfMaxId = $users->slice(0, 2)->last()->id;
    $broadcast->update([
        'last_processed_id' => $firstHalfMaxId,
        'recipients_count' => 2,
    ]);

    (new SendBroadcastJob($broadcast))->handle(
        app(BroadcastAudienceResolver::class),
        app(NotificationDispatcher::class),
    );

    // First two users skipped (no new dispatch); last two delivered.
    Notification::assertNotSentTo($users->get(0), GenericNotification::class);
    Notification::assertNotSentTo($users->get(1), GenericNotification::class);
    Notification::assertSentTo($users->get(2), GenericNotification::class);
    Notification::assertSentTo($users->get(3), GenericNotification::class);

    // recipients_count incremented by the new deliveries — total = 4.
    expect($broadcast->fresh()->recipients_count)->toBe(4);
});

it('handles an empty audience without error', function (): void {
    $broadcast = BroadcastNotification::query()->create([
        'tenant_id' => null,
        'subject' => 'Hello',
        'body' => 'No-one',
        'channels' => ['push'],
        'audience' => ['user_ids' => []],
        'status' => 'queued',
    ]);

    (new SendBroadcastJob($broadcast))->handle(
        app(BroadcastAudienceResolver::class),
        app(NotificationDispatcher::class),
    );

    Notification::assertNothingSent();
    expect($broadcast->fresh()->status)->toBe('sent')
        ->and($broadcast->fresh()->recipients_count)->toBeNull();
});
