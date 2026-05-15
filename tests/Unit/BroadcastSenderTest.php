<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Jobs\SendBroadcastJob;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\Services\BroadcastSender;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    $this->sender = new BroadcastSender;
});

it('transitions to status=queued and dispatches the job immediately when no scheduled_at is set', function (): void {
    $broadcast = BroadcastNotification::query()->create([
        'subject' => 's', 'body' => 'b',
        'channels' => ['push'], 'audience' => ['user_ids' => []],
        'status' => 'draft',
    ]);

    $this->sender->send($broadcast);

    expect($broadcast->fresh()->status)->toBe('queued');
    Queue::assertPushed(SendBroadcastJob::class);
});

it('transitions to status=scheduled and delays the job when scheduled_at is in the future', function (): void {
    $when = now()->addHour();

    $broadcast = BroadcastNotification::query()->create([
        'subject' => 's', 'body' => 'b',
        'channels' => ['push'], 'audience' => ['user_ids' => []],
        'status' => 'draft',
        'scheduled_at' => $when,
    ]);

    $this->sender->send($broadcast);

    expect($broadcast->fresh()->status)->toBe('scheduled');

    Queue::assertPushed(SendBroadcastJob::class);
    // The job carries a non-null delay timestamp — its exact value passes
    // through Laravel's `delay()` and Carbon, so compare to the second
    // rather than expecting object identity.
    $pushed = Queue::pushed(SendBroadcastJob::class)->first();
    expect($pushed->delay)->not->toBeNull()
        ->and($pushed->delay->timestamp)->toBe($when->timestamp);
});

it('treats a past scheduled_at as immediate (queued, no delay)', function (): void {
    $broadcast = BroadcastNotification::query()->create([
        'subject' => 's', 'body' => 'b',
        'channels' => ['push'], 'audience' => ['user_ids' => []],
        'status' => 'draft',
        'scheduled_at' => now()->subHour(),
    ]);

    $this->sender->send($broadcast);

    expect($broadcast->fresh()->status)->toBe('queued');
    Queue::assertPushed(SendBroadcastJob::class, fn ($job): bool => $job->delay === null);
});
