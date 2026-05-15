<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Defaults\EloquentPreferenceResolver;
use Devletes\NotificationsMax\Defaults\FilamentTenantResolver;
use Devletes\NotificationsMax\Models\NotificationTypeOverride;
use Devletes\NotificationsMax\Models\UserNotificationPreference;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use Devletes\NotificationsMax\Tests\Stubs\User;

beforeEach(function (): void {
    config(['notifications' => [
        'task.assigned' => [
            'category' => 'work',
            'title' => 'Task assigned',
            'body' => 'A task was assigned',
            'default_channels' => ['push'],
            'allowed_channels' => ['push', 'email'],
        ],
        'security.alert' => [
            'category' => 'security',
            'title' => 'Security alert',
            'body' => 'Important',
            'default_channels' => ['push', 'email'],
            'allowed_channels' => ['push', 'email'],
            'mandatory' => true,
        ],
    ]]);

    // Flush the registry singleton so the per-test config above is what
    // it sees on first read.
    app(NotificationTypeRegistry::class)->flush();

    $registry = app(NotificationTypeRegistry::class);
    $content = new NotificationContentResolver($registry);
    $tenant = new FilamentTenantResolver;

    $this->resolver = new EloquentPreferenceResolver($registry, $content, $tenant);

    $this->user = User::query()->create(['email' => 'alice@example.test']);
});

it('returns the type defaults expanded to physical channels when no explicit prefs exist', function (): void {
    // 'task.assigned' default_channels = ['push']; 'push' physical = ['database', 'broadcast'].
    expect($this->resolver->channelsFor($this->user, 'task.assigned'))
        ->toBe(['database', 'broadcast']);
});

it('honours an explicit user preference that disables a default channel', function (): void {
    UserNotificationPreference::set(
        userId: $this->user->id,
        typeKey: 'task.assigned',
        channel: 'push',
        enabled: false,
    );

    expect($this->resolver->channelsFor($this->user, 'task.assigned'))
        ->toBe([]);
});

it('honours an explicit user preference that enables a non-default channel', function (): void {
    UserNotificationPreference::set(
        userId: $this->user->id,
        typeKey: 'task.assigned',
        channel: 'email',
        enabled: true,
    );

    // Push (default) + email (explicit) → physical: database, broadcast, mail.
    expect($this->resolver->channelsFor($this->user, 'task.assigned'))
        ->toBe(['database', 'broadcast', 'mail']);
});

it('mandatory types ignore explicit user preferences', function (): void {
    UserNotificationPreference::set(
        userId: $this->user->id,
        typeKey: 'security.alert',
        channel: 'email',
        enabled: false,
    );
    UserNotificationPreference::set(
        userId: $this->user->id,
        typeKey: 'security.alert',
        channel: 'push',
        enabled: false,
    );

    // Mandatory → all allowed_channels fire regardless.
    expect($this->resolver->channelsFor($this->user, 'security.alert'))
        ->toBe(['database', 'broadcast', 'mail']);
});

it('admin allowance gates which channels the user can opt in to', function (): void {
    config(['notifications-max.content_source' => 'database']);

    NotificationTypeOverride::query()->create([
        'tenant_id' => null,
        'type_key' => 'task.assigned',
        'allowed_channels' => ['push'], // admin disabled email
    ]);

    // User tries to enable email — admin's allowance vetoes it.
    UserNotificationPreference::set(
        userId: $this->user->id,
        typeKey: 'task.assigned',
        channel: 'email',
        enabled: true,
    );

    expect($this->resolver->channelsFor($this->user, 'task.assigned'))
        ->toBe(['database', 'broadcast']); // email never expanded
});

it('returns empty when admin has disabled every channel for an optional type', function (): void {
    config(['notifications-max.content_source' => 'database']);

    NotificationTypeOverride::query()->create([
        'tenant_id' => null,
        'type_key' => 'task.assigned',
        'allowed_channels' => [],
    ]);

    expect($this->resolver->channelsFor($this->user, 'task.assigned'))
        ->toBe([]);
});
