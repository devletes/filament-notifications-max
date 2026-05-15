<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Registry\NotificationType;

it('fromConfig populates fields from the registry entry', function (): void {
    $type = NotificationType::fromConfig('demo.key', [
        'category' => 'work',
        'group' => 'tasks',
        'group_label' => 'Needs your approval',
        'label' => 'Task assigned',
        'description' => 'A task was assigned to you',
        'title' => 'New task: {title}',
        'body' => 'From {sender}',
        'icon' => 'heroicon-o-clipboard',
        'color' => 'primary',
        'status' => 'info',
        'target_panel' => 'admin',
        'action_resource' => 'tasks',
        'action_record_key' => 'task_id',
        'duration' => 7000,
        'actions' => [['name' => 'view']],
        'default_channels' => ['push'],
        'allowed_channels' => ['push', 'email'],
        'mandatory' => true,
        'rate_limit' => ['max' => 5, 'per_minutes' => 10],
        'notification_class' => 'App\\Notifications\\CustomTask',
    ]);

    expect($type->key)->toBe('demo.key')
        ->and($type->category)->toBe('work')
        ->and($type->group)->toBe('tasks')
        ->and($type->groupLabel)->toBe('Needs your approval')
        ->and($type->label)->toBe('Task assigned')
        ->and($type->title)->toBe('New task: {title}')
        ->and($type->icon)->toBe('heroicon-o-clipboard')
        ->and($type->color)->toBe('primary')
        ->and($type->status)->toBe('info')
        ->and($type->targetPanel)->toBe('admin')
        ->and($type->actionResource)->toBe('tasks')
        ->and($type->actionRecordKey)->toBe('task_id')
        ->and($type->duration)->toBe(7000)
        ->and($type->actions)->toBe([['name' => 'view']])
        ->and($type->defaultChannels)->toBe(['push'])
        ->and($type->allowedChannels)->toBe(['push', 'email'])
        ->and($type->mandatory)->toBeTrue()
        ->and($type->rateLimit)->toBe(['max' => 5, 'per_minutes' => 10])
        ->and($type->notificationClass)->toBe('App\\Notifications\\CustomTask');
});

it('falls back to type_defaults for missing fields', function (): void {
    config(['notifications-max.type_defaults' => [
        'icon' => 'heroicon-o-bell',
        'target_panel' => 'employee',
        'category' => 'general',
        'default_channels' => ['push'],
        'allowed_channels' => ['push', 'email'],
    ]]);

    $type = NotificationType::fromConfig('demo.minimal', [
        'label' => 'Minimal',
        'title' => 'Title',
        'body' => 'Body',
    ]);

    expect($type->icon)->toBe('heroicon-o-bell')
        ->and($type->targetPanel)->toBe('employee')
        ->and($type->category)->toBe('general')
        ->and($type->defaultChannels)->toBe(['push'])
        ->and($type->allowedChannels)->toBe(['push', 'email']);
});

it('treats empty-string group / group_label as null', function (): void {
    $type = NotificationType::fromConfig('demo.key', [
        'group' => '',
        'group_label' => '',
    ]);

    expect($type->group)->toBeNull()
        ->and($type->groupLabel)->toBeNull();
});

it('accepts the persistent string for duration', function (): void {
    $type = NotificationType::fromConfig('demo.key', ['duration' => 'persistent']);

    expect($type->duration)->toBe('persistent');
});

it('coerces non-int / non-persistent duration to null', function (): void {
    $type = NotificationType::fromConfig('demo.key', ['duration' => 'something-else']);

    expect($type->duration)->toBeNull();
});

it('channelIsOptional returns false for mandatory types regardless of channel', function (): void {
    $type = NotificationType::fromConfig('demo.key', [
        'mandatory' => true,
        'allowed_channels' => ['push', 'email'],
    ]);

    expect($type->channelIsOptional('push'))->toBeFalse()
        ->and($type->channelIsOptional('email'))->toBeFalse();
});

it('channelIsOptional returns true for allowed channels on non-mandatory types', function (): void {
    $type = NotificationType::fromConfig('demo.key', [
        'mandatory' => false,
        'allowed_channels' => ['push', 'email'],
    ]);

    expect($type->channelIsOptional('push'))->toBeTrue()
        ->and($type->channelIsOptional('email'))->toBeTrue()
        ->and($type->channelIsOptional('sms'))->toBeFalse();
});

it('channelIsOnByDefault returns true only for channels in default_channels', function (): void {
    $type = NotificationType::fromConfig('demo.key', [
        'default_channels' => ['push'],
        'allowed_channels' => ['push', 'email'],
    ]);

    expect($type->channelIsOnByDefault('push'))->toBeTrue()
        ->and($type->channelIsOnByDefault('email'))->toBeFalse();
});
