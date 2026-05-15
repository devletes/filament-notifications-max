<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Channels\BroadcastChannelHandler;
use Devletes\NotificationsMax\Channels\DatabaseChannelHandler;
use Devletes\NotificationsMax\Channels\MailChannelHandler;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use Devletes\NotificationsMax\Tests\Stubs\User;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;

beforeEach(function (): void {
    config(['notifications' => [
        'demo.simple' => [
            'title' => 'Title {name}',
            'body' => 'Body {name}',
            'default_channels' => ['push'],
            'allowed_channels' => ['push', 'email'],
        ],
        'demo.with.email' => [
            'title' => 'Top title',
            'body' => 'Top body',
            'content' => [
                'email' => [
                    'subject' => 'Email subject {name}',
                    'body' => '<p>Email body {name}</p>',
                    'template' => 'default',
                ],
            ],
            'default_channels' => ['email'],
            'allowed_channels' => ['email'],
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();
});

it('DatabaseChannelHandler returns the notification\'s buildFilamentPayload', function (): void {
    $notification = new GenericNotification('demo.simple', ['name' => 'Alice']);

    $payload = (new DatabaseChannelHandler)->send(new stdClass, $notification);

    expect($payload)->toBeArray()
        ->and($payload)->toBe($notification->buildFilamentPayload());
});

it('BroadcastChannelHandler wraps resolveBroadcastData in a BroadcastMessage', function (): void {
    $notification = new GenericNotification('demo.simple', ['name' => 'Bob']);

    $message = (new BroadcastChannelHandler)->send(new stdClass, $notification);

    expect($message)->toBeInstanceOf(BroadcastMessage::class)
        ->and($message->data)->toBe($notification->resolveBroadcastData());
});

it('MailChannelHandler builds a MailMessage with subject + body from channel content', function (): void {
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.with.email', ['name' => 'Carol']);

    $handler = new MailChannelHandler(app(NotificationContentResolver::class));
    $message = $handler->send($user, $notification);

    expect($message)->toBeInstanceOf(MailMessage::class)
        ->and($message->subject)->toBe('Email subject Carol');
});

it('MailChannelHandler falls back to type top-level title/body when email content is absent', function (): void {
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.simple', ['name' => 'Dan']);

    $handler = new MailChannelHandler(app(NotificationContentResolver::class));
    $message = $handler->send($user, $notification);

    // Top-level title is used as the email subject via the resolver
    // fallback.
    expect($message->subject)->toBe('Title Dan');
});

it('MailChannelHandler resolves a registered template view', function (): void {
    config(['notifications-max.email_templates' => [
        'default' => 'filament-notifications-max::mail.default',
    ]]);

    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.with.email', ['name' => 'Eve']);

    $message = (new MailChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->view)->toBe('filament-notifications-max::mail.default');
});
