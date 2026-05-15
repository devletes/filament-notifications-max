<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Defaults\AudienceResolver;
use Devletes\NotificationsMax\Defaults\FilamentTenantResolver;
use Devletes\NotificationsMax\Defaults\ImmediateBroadcastReleasePipeline;
use Devletes\NotificationsMax\Jobs\SendBroadcastJob;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\Services\BroadcastSender;
use Devletes\NotificationsMax\Support\BroadcastReleasePrompt;
use Devletes\NotificationsMax\Support\BroadcastReleaseResult;
use Devletes\NotificationsMax\Tests\Stubs\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    $this->pipeline = new ImmediateBroadcastReleasePipeline(
        sender: new BroadcastSender,
        audience: new AudienceResolver(new FilamentTenantResolver),
    );
});

it('handle() queues the broadcast and returns a "Broadcast queued" result for immediate sends', function (): void {
    $broadcast = BroadcastNotification::query()->create([
        'subject' => 's', 'body' => 'b',
        'channels' => ['push'], 'audience' => ['user_ids' => []],
        'status' => 'draft',
    ]);

    $result = $this->pipeline->handle($broadcast);

    expect($result)->toBeInstanceOf(BroadcastReleaseResult::class)
        ->and($result->title)->toBe('Broadcast queued')
        ->and($broadcast->fresh()->status)->toBe('queued');

    Queue::assertPushed(SendBroadcastJob::class);
});

it('handle() schedules and returns a "Broadcast scheduled" result when scheduled_at is in the future', function (): void {
    $broadcast = BroadcastNotification::query()->create([
        'subject' => 's', 'body' => 'b',
        'channels' => ['push'], 'audience' => ['user_ids' => []],
        'status' => 'draft',
        'scheduled_at' => now()->addDay(),
    ]);

    $result = $this->pipeline->handle($broadcast);

    expect($result->title)->toBe('Broadcast scheduled')
        ->and($broadcast->fresh()->status)->toBe('scheduled');
});

it('describeAction() returns a Publish prompt with recipient count for immediate broadcasts', function (): void {
    $alice = User::query()->create(['email' => 'a@x.test']);
    $bob = User::query()->create(['email' => 'b@x.test']);

    $broadcast = BroadcastNotification::query()->create([
        'subject' => 's', 'body' => 'b',
        'channels' => ['push'],
        'audience' => ['user_ids' => [$alice->id, $bob->id]],
        'status' => 'draft',
    ]);

    $prompt = $this->pipeline->describeAction($broadcast);

    expect($prompt)->toBeInstanceOf(BroadcastReleasePrompt::class)
        ->and($prompt->label)->toBe('Publish')
        ->and($prompt->confirmation)->toContain('2 recipients');
});

it('describeAction() returns a Schedule prompt for future-scheduled broadcasts', function (): void {
    $user = User::query()->create(['email' => 'a@x.test']);

    $broadcast = BroadcastNotification::query()->create([
        'subject' => 's', 'body' => 'b',
        'channels' => ['push'],
        'audience' => ['user_ids' => [$user->id]],
        'status' => 'draft',
        'scheduled_at' => now()->addDay(),
    ]);

    $prompt = $this->pipeline->describeAction($broadcast);

    expect($prompt->label)->toBe('Schedule')
        ->and($prompt->confirmation)->toContain('1 recipient');
});
