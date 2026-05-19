<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Channels\SlackChannelHandler;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use Devletes\NotificationsMax\Tests\Stubs\User;

// Slack channel testing requires the optional `laravel/slack-notification-
// channel` package — Laravel removed `SlackMessage` from core in v9. Skip
// the whole file when the package isn't installed so test runs stay green
// for hosts that haven't opted in to Slack delivery.
beforeEach(function (): void {
    if (! class_exists(\Illuminate\Notifications\Slack\SlackMessage::class)) {
        test()->markTestSkipped(
            'laravel/slack-notification-channel is not installed — install it to exercise this test file.'
        );
    }

    config(['notifications-max.channels.slack' => [
        'label' => 'Slack',
        'physical' => ['slack'],
        'richness' => 'markdown',
        'content_fields' => ['body' => 'markdown'],
    ]]);

    config(['notifications' => [
        'demo.slack' => [
            'title' => 'Top title',
            'body' => 'Top body',
            'content' => [
                'slack' => [
                    'body' => '*Hello* {name} — please review.',
                ],
            ],
            'default_channels' => ['slack'],
            'allowed_channels' => ['slack'],
        ],
    ]]);

    app(NotificationTypeRegistry::class)->flush();
});

it('SlackChannelHandler returns a SlackMessage', function (): void {
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.slack', ['name' => 'Alice']);

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message)->toBeInstanceOf(\Illuminate\Notifications\Slack\SlackMessage::class);
});

it('SlackChannelHandler preserves mrkdwn formatting characters in the template', function (): void {
    // Template author wrote `*Hello*` intending Slack-bold. The render
    // pipeline must NOT escape those formatting chars — they're trusted
    // template input.
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.slack', ['name' => 'Alice']);

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->text)->toBe('*Hello* Alice — please review.');
});

it('SlackChannelHandler backslash-escapes mrkdwn formatting characters in interpolated context values', function (): void {
    // Context-supplied value contains `*not bold*` — without escaping
    // it would trigger bold formatting after substitution, garbling
    // the rendered Slack message. The render pipeline escapes those
    // characters in interpolated values (templates remain trusted).
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.slack', ['name' => '*not bold*']);

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->text)->toBe('*Hello* \\*not bold\\* — please review.');
});

it('SlackChannelHandler HTML-entity-escapes < > & in interpolated values (Slack convention)', function (): void {
    // Slack's docs require HTML-entity escaping of `&`, `<`, `>` in any
    // user-supplied text so that `<url>` isn't interpreted as a link.
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.slack', ['name' => '<root> & friends']);

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->text)->toBe('*Hello* &lt;root&gt; &amp; friends — please review.');
});

it('SlackChannelHandler falls back to the type top-level body when slack channel content is absent', function (): void {
    // Type config has top-level `body` but no `content.slack.body` —
    // resolver's fallback chain pulls top-level body through, and the
    // handler still renders with markdown richness.
    config(['notifications' => [
        'demo.bare' => [
            'title' => 'Top title',
            'body' => 'Bare {name}',
            'default_channels' => ['slack'],
            'allowed_channels' => ['slack'],
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();

    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.bare', ['name' => 'Alice']);

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->text)->toBe('Bare Alice');
});
