<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Jobs\SendDeferredNotificationJob;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
use Devletes\NotificationsMax\Tests\Stubs\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    config(['notifications' => [
        'demo.normal' => [
            'category' => 'demo',
            'title' => 'Demo',
            'body' => 'Demo body',
            'default_channels' => ['push'],
            'allowed_channels' => ['push', 'email'],
        ],
        'demo.mandatory' => [
            'category' => 'demo',
            'title' => 'Required',
            'body' => 'Cannot mute',
            'default_channels' => ['push'],
            'allowed_channels' => ['push', 'email'],
            'mandatory' => true,
        ],
        'demo.rate.limited' => [
            'category' => 'demo',
            'title' => 'Limited',
            'body' => 'Throttled',
            'default_channels' => ['push'],
            'allowed_channels' => ['push'],
            'rate_limit' => ['max' => 2, 'per_minutes' => 5],
        ],
        'demo.missing.recipients' => [
            'category' => 'demo',
            'title' => 'No-one',
            'body' => 'No recipients',
            'default_channels' => ['push'],
            'allowed_channels' => ['push'],
        ],
    ]]);

    app(NotificationTypeRegistry::class)->flush();

    $this->dispatcher = app(NotificationDispatcher::class);

    Notification::fake();
});

it('throws when the type key is not registered', function (): void {
    $user = User::query()->create(['email' => 'a@x.test']);

    expect(fn () => $this->dispatcher->send('not.real', [], $user))
        ->toThrow(RuntimeException::class, 'is not registered');
});

it('returns silently when the recipient collection is empty', function (): void {
    $this->dispatcher->send('demo.normal', [], []);

    Notification::assertNothingSent();
});

it('dispatches a GenericNotification to a single Authenticatable recipient', function (): void {
    $user = User::query()->create(['email' => 'a@x.test']);

    $this->dispatcher->send('demo.normal', ['name' => 'Ada'], $user);

    Notification::assertSentTo($user, GenericNotification::class, function (GenericNotification $n) {
        return $n->typeKey === 'demo.normal'
            && $n->context['name'] === 'Ada';
    });
});

it('hydrates an array of integer user ids into model instances', function (): void {
    $a = User::query()->create(['email' => 'a@x.test']);
    $b = User::query()->create(['email' => 'b@x.test']);

    $this->dispatcher->send('demo.normal', [], [$a->id, $b->id]);

    Notification::assertSentTo($a, GenericNotification::class);
    Notification::assertSentTo($b, GenericNotification::class);
});

it('accepts a Collection of user models as-is', function (): void {
    $users = collect([
        User::query()->create(['email' => 'a@x.test']),
        User::query()->create(['email' => 'b@x.test']),
    ]);

    $this->dispatcher->send('demo.normal', [], $users);

    Notification::assertCount(2);
});

it('throws when recipients span multiple tenants', function (): void {
    $a = User::query()->create(['email' => 'a@x.test', 'tenant_id' => 1]);
    $b = User::query()->create(['email' => 'b@x.test', 'tenant_id' => 2]);

    expect(fn () => $this->dispatcher->send('demo.normal', [], collect([$a, $b])))
        ->toThrow(RuntimeException::class, 'span multiple tenants');
});

it('throws when recipients mix null and non-null tenant ids', function (): void {
    $tenanted = User::query()->create(['email' => 'a@x.test', 'tenant_id' => 1]);
    $orphan = User::query()->create(['email' => 'b@x.test', 'tenant_id' => null]);

    expect(fn () => $this->dispatcher->send('demo.normal', [], collect([$tenanted, $orphan])))
        ->toThrow(RuntimeException::class, 'span multiple tenants');
});

it('passes when all recipients share the same tenant id', function (): void {
    $a = User::query()->create(['email' => 'a@x.test', 'tenant_id' => 7]);
    $b = User::query()->create(['email' => 'b@x.test', 'tenant_id' => 7]);

    $this->dispatcher->send('demo.normal', [], collect([$a, $b]));

    Notification::assertCount(2);
});

it('passes when single-tenant install — every recipient has null tenant_id', function (): void {
    $a = User::query()->create(['email' => 'a@x.test', 'tenant_id' => null]);
    $b = User::query()->create(['email' => 'b@x.test', 'tenant_id' => null]);

    $this->dispatcher->send('demo.normal', [], collect([$a, $b]));

    Notification::assertCount(2);
});

it('applies per-(user, type) rate limit by filtering throttled recipients out', function (): void {
    RateLimiter::clear('notif-throttle:1:demo.rate.limited');

    $user = User::query()->create(['email' => 'a@x.test']); // id=1

    // First two dispatches go through, third one gets dropped (max=2).
    $this->dispatcher->send('demo.rate.limited', [], $user);
    $this->dispatcher->send('demo.rate.limited', [], $user);
    $this->dispatcher->send('demo.rate.limited', [], $user);

    Notification::assertSentToTimes($user, GenericNotification::class, 2);
});

it('mandatory types bypass rate limiting', function (): void {
    config(['notifications' => array_merge(config('notifications'), [
        'demo.mandatory.limited' => [
            'title' => 'Mandatory + limited',
            'body' => 'fires anyway',
            'default_channels' => ['push'],
            'allowed_channels' => ['push'],
            'mandatory' => true,
            'rate_limit' => ['max' => 1, 'per_minutes' => 5],
        ],
    ])]);
    app(NotificationTypeRegistry::class)->flush();

    $user = User::query()->create(['email' => 'a@x.test']);

    $this->dispatcher->send('demo.mandatory.limited', [], $user);
    $this->dispatcher->send('demo.mandatory.limited', [], $user);
    $this->dispatcher->send('demo.mandatory.limited', [], $user);

    Notification::assertSentToTimes($user, GenericNotification::class, 3);
});

it('rate_limit max <= 0 disables throttling', function (): void {
    config(['notifications' => array_merge(config('notifications'), [
        'demo.unlimited' => [
            'title' => 'Unlimited',
            'body' => 'goes',
            'default_channels' => ['push'],
            'allowed_channels' => ['push'],
            'rate_limit' => ['max' => 0, 'per_minutes' => 1],
        ],
    ])]);
    app(NotificationTypeRegistry::class)->flush();

    $user = User::query()->create(['email' => 'a@x.test']);

    for ($i = 0; $i < 5; $i++) {
        $this->dispatcher->send('demo.unlimited', [], $user);
    }

    Notification::assertSentToTimes($user, GenericNotification::class, 5);
});

// ─── Scheduling ────────────────────────────────────────────────────────

it('defers dispatch via SendDeferredNotificationJob when delayUntil is in the future', function (): void {
    Bus::fake([SendDeferredNotificationJob::class]);

    $user = User::query()->create(['email' => 'a@x.test', 'tenant_id' => 7]);
    $when = now()->addHour();

    $this->dispatcher->send('demo.normal', ['name' => 'Ada'], $user, $when);

    // No immediate fire — the dispatch went to the queue instead.
    Notification::assertNothingSent();

    Bus::assertDispatched(
        SendDeferredNotificationJob::class,
        function (SendDeferredNotificationJob $job) use ($user, $when): bool {
            return $job->typeKey === 'demo.normal'
                && $job->context['name'] === 'Ada'
                && $job->userIds === [$user->id]
                && $job->tenantId === 7
                && $job->delay !== null
                && $job->delay->timestamp === $when->timestamp;
        },
    );
});

it('runs immediately when delayUntil is in the past', function (): void {
    Bus::fake([SendDeferredNotificationJob::class]);

    $user = User::query()->create(['email' => 'a@x.test']);

    $this->dispatcher->send('demo.normal', [], $user, now()->subMinute());

    Bus::assertNotDispatched(SendDeferredNotificationJob::class);
    Notification::assertSentTo($user, GenericNotification::class);
});

it('schedule() is sugar over send() with the delay argument named', function (): void {
    Bus::fake([SendDeferredNotificationJob::class]);

    $user = User::query()->create(['email' => 'a@x.test']);
    $when = now()->addDay();

    $this->dispatcher->schedule($when, 'demo.normal', ['name' => 'Ada'], $user);

    Bus::assertDispatched(
        SendDeferredNotificationJob::class,
        fn (SendDeferredNotificationJob $job): bool => $job->typeKey === 'demo.normal'
            && $job->userIds === [$user->id]
            && $job->delay !== null
            && $job->delay->timestamp === $when->timestamp,
    );
});

it('scheduled dispatch fails fast on cross-tenant recipients', function (): void {
    Bus::fake([SendDeferredNotificationJob::class]);

    $a = User::query()->create(['email' => 'a@x.test', 'tenant_id' => 1]);
    $b = User::query()->create(['email' => 'b@x.test', 'tenant_id' => 2]);

    expect(fn () => $this->dispatcher->send('demo.normal', [], collect([$a, $b]), now()->addHour()))
        ->toThrow(RuntimeException::class, 'span multiple tenants');

    Bus::assertNotDispatched(SendDeferredNotificationJob::class);
});

it('SendDeferredNotificationJob re-enters the dispatcher when handled', function (): void {
    Notification::fake();

    $user = User::query()->create(['email' => 'a@x.test']);

    $job = new SendDeferredNotificationJob(
        typeKey: 'demo.normal',
        context: ['name' => 'Ada'],
        userIds: [$user->id],
        tenantId: null,
    );

    $job->handle($this->dispatcher);

    Notification::assertSentTo($user, GenericNotification::class, function (GenericNotification $n): bool {
        return $n->typeKey === 'demo.normal' && $n->context['name'] === 'Ada';
    });
});
