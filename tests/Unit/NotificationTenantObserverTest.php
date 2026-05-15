<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Observers\NotificationTenantObserver;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Tests\Stubs\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

beforeEach(function (): void {
    config(['notifications' => [
        'demo.tenant' => [
            'title' => 'Hi',
            'body' => 'There',
            'default_channels' => ['push'],
            'allowed_channels' => ['push'],
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();

    // The observer caches its column-existence check across tests. Force
    // re-detection so each test starts from a clean slate.
    $cache = new ReflectionProperty(NotificationTenantObserver::class, 'tenantColumnExists');
    $cache->setValue(null, null);
});

it('stamps tenant_id on a new notification when the notifiable has one', function (): void {
    $user = User::query()->create(['email' => 'a@x.test', 'tenant_id' => 7]);

    NotificationFacade::send($user, new GenericNotification('demo.tenant'));

    expect(DatabaseNotification::query()->first()->tenant_id)->toBe(7);
});

it('leaves tenant_id null when the notifiable has no tenant_id', function (): void {
    $user = User::query()->create(['email' => 'a@x.test', 'tenant_id' => null]);

    NotificationFacade::send($user, new GenericNotification('demo.tenant'));

    expect(DatabaseNotification::query()->first()->tenant_id)->toBeNull();
});

it('does not overwrite an explicitly-set tenant_id', function (): void {
    $user = User::query()->create(['email' => 'a@x.test', 'tenant_id' => 7]);

    // Create the notification row directly with a different tenant_id —
    // the observer should leave it alone because the attribute is already
    // set.
    $row = new DatabaseNotification([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => GenericNotification::class,
        'notifiable_id' => $user->id,
        'notifiable_type' => User::class,
        'data' => [],
        'tenant_id' => 999,
    ]);
    $row->setRelation('notifiable', $user);

    (new NotificationTenantObserver)->creating($row);

    expect($row->tenant_id)->toBe(999);
});
