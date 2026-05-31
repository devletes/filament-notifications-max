<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Channels\SlackChannelHandler;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use Devletes\NotificationsMax\Tests\Stubs\User;
use Illuminate\Notifications\Slack\SlackMessage;

// Slack channel testing requires the optional `laravel/slack-notification-
// channel` package — Laravel removed `SlackMessage` from core in v9. Skip
// the whole file when the package isn't installed so test runs stay green
// for hosts that haven't opted in to Slack delivery.
beforeEach(function (): void {
    if (! class_exists(SlackMessage::class)) {
        test()->markTestSkipped(
            'laravel/slack-notification-channel is not installed — install it to exercise this test file.'
        );
    }

    config(['notifications-max.channels.slack' => [
        'label' => 'Slack',
        'physical' => ['slack'],
        'richness' => 'markdown',
        'content_fields' => ['title' => 'string', 'body' => 'markdown'],
    ]]);

    config(['notifications' => [
        'demo.slack' => [
            'title' => 'Top title',
            'body' => 'Top body',
            'content' => [
                'slack' => [
                    // Constant (non-interpolated) title keeps the body-focused
                    // assertions below readable: every message leads with the
                    // same `Leave request` headline.
                    'title' => 'Leave request',
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

    expect($message)->toBeInstanceOf(SlackMessage::class);
});

it('SlackChannelHandler leads with the title as a bold headline above the body', function (): void {
    // The body alone ("All approvals are in.") is ambiguous; the title gives
    // it context. Both render in one mrkdwn text field — title bolded on its
    // own line, body beneath — mirroring the push/email framing.
    config(['notifications' => [
        'demo.titled' => [
            'title' => 'Fallback title',
            'body' => 'Fallback body',
            'content' => [
                'slack' => [
                    'title' => 'Leave request from {name}',
                    'body' => 'All approvals are in.',
                ],
            ],
            'default_channels' => ['slack'],
            'allowed_channels' => ['slack'],
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();

    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.titled', ['name' => 'Faizan']);

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->toArray()['text'])->toBe(
        "*Leave request from Faizan*\nAll approvals are in."
    );
});

it('SlackChannelHandler escapes interpolated values in the title (markdown dialect)', function (): void {
    // Title interpolation is escaped just like the body — a context value
    // carrying mrkdwn chars can't break out of the bold headline.
    config(['notifications' => [
        'demo.titled' => [
            'title' => 'Fallback title',
            'body' => 'Body',
            'content' => [
                'slack' => [
                    'title' => 'Hi {name}',
                    'body' => 'Body',
                ],
            ],
            'default_channels' => ['slack'],
            'allowed_channels' => ['slack'],
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();

    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.titled', ['name' => '*Bob*']);

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->toArray()['text'])->toBe("*Hi \\*Bob\\**\nBody");
});

it('SlackChannelHandler omits the headline when the title resolves empty', function (): void {
    // A type with no title (top-level or per-channel) renders body-only —
    // no stray empty `**` headline.
    config(['notifications' => [
        'demo.bodyonly' => [
            'title' => '',
            'body' => 'Just the body',
            'default_channels' => ['slack'],
            'allowed_channels' => ['slack'],
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();

    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.bodyonly');

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->toArray()['text'])->toBe('Just the body');
});

it('SlackChannelHandler preserves mrkdwn formatting characters in the template', function (): void {
    // Template author wrote `*Hello*` intending Slack-bold. The render
    // pipeline must NOT escape those formatting chars — they're trusted
    // template input.
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.slack', ['name' => 'Alice']);

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->toArray()['text'])->toBe("*Leave request*\n*Hello* Alice — please review.");
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

    expect($message->toArray()['text'])->toBe("*Leave request*\n*Hello* \\*not bold\\* — please review.");
});

it('SlackChannelHandler HTML-entity-escapes < > & in interpolated values (Slack convention)', function (): void {
    // Slack's docs require HTML-entity escaping of `&`, `<`, `>` in any
    // user-supplied text so that `<url>` isn't interpreted as a link.
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.slack', ['name' => '<root> & friends']);

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->toArray()['text'])->toBe("*Leave request*\n*Hello* &lt;root&gt; &amp; friends — please review.");
});

it('SlackChannelHandler appends the primary action as an mrkdwn link', function (): void {
    // A "View" affordance: the resolved action URL is appended below the
    // body as a Slack `<url|label>` link so the recipient can jump to the
    // record.
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.slack', [
        'name' => 'Alice',
        'action_url' => 'https://app.test/go/55',
    ]);

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->toArray()['text'])->toBe(
        "*Leave request*\n*Hello* Alice — please review.\n\n<https://app.test/go/55|View>"
    );
});

it('SlackChannelHandler honours a custom action label in the link', function (): void {
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.slack', [
        'name' => 'Alice',
        'action_url' => 'https://app.test/go/55',
        'action_label' => 'Open request',
    ]);

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->toArray()['text'])->toBe(
        "*Leave request*\n*Hello* Alice — please review.\n\n<https://app.test/go/55|Open request>"
    );
});

it('SlackChannelHandler sanitises link-delimiter characters in the action label', function (): void {
    // The label flows into `<url|label>` — a stray `|`, `<`, or `>` would
    // break the link syntax, so those are entity-escaped / dropped.
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.slack', [
        'name' => 'Alice',
        'action_url' => 'https://app.test/go/55',
        'action_label' => 'View <A|B> & more',
    ]);

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->toArray()['text'])->toBe(
        "*Leave request*\n*Hello* Alice — please review.\n\n<https://app.test/go/55|View &lt;A B&gt; &amp; more>"
    );
});

it('SlackChannelHandler leaves the text link-free when the type has no action', function (): void {
    // demo.slack declares no actions / action_resource and the context
    // carries no action_url, so there is nothing to link — title + body only.
    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.slack', ['name' => 'Alice']);

    $message = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification);

    expect($message->toArray()['text'])->toBe("*Leave request*\n*Hello* Alice — please review.");
});

it('SlackChannelHandler falls back to the type top-level title and body when slack channel content is absent', function (): void {
    // Type config has top-level `title` / `body` but no `content.slack` —
    // resolver's fallback chain pulls both through, and the handler still
    // renders with markdown richness.
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

    expect($message->toArray()['text'])->toBe("*Top title*\nBare Alice");
});

// ─── 'blocks' format ──────────────────────────────────────────────────────

it('SlackChannelHandler blocks format renders divider + header + section with a primary View button', function (): void {
    config(['notifications-max.channels.slack.format' => 'blocks']);

    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.slack', [
        'name' => 'Alice',
        'action_url' => 'https://app.test/go/55',
    ]);

    $payload = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification)
        ->toArray();

    // Notification preview / fallback for surfaces that can't render blocks.
    expect($payload['text'])->toBe("Leave request\n*Hello* Alice — please review.");

    $blocks = $payload['blocks'];

    expect($blocks[0])->toBe(['type' => 'divider']);

    // Header is plain_text (no mrkdwn) — the title rendered without escaping.
    expect($blocks[1]['type'])->toBe('header')
        ->and($blocks[1]['text']['type'])->toBe('plain_text')
        ->and($blocks[1]['text']['text'])->toBe('Leave request');

    // Section body is mrkdwn — template formatting preserved, value escaped.
    expect($blocks[2]['type'])->toBe('section')
        ->and($blocks[2]['text']['type'])->toBe('mrkdwn')
        ->and($blocks[2]['text']['text'])->toBe('*Hello* Alice — please review.');

    // Primary "View" button as the section accessory, carrying the action URL.
    expect($blocks[2]['accessory']['type'])->toBe('button')
        ->and($blocks[2]['accessory']['url'])->toBe('https://app.test/go/55')
        ->and($blocks[2]['accessory']['style'])->toBe('primary')
        ->and($blocks[2]['accessory']['text']['text'])->toBe('View');
});

it('SlackChannelHandler blocks format escapes interpolated context values in the section body', function (): void {
    config(['notifications-max.channels.slack.format' => 'blocks']);

    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.slack', ['name' => '*not bold*']);

    $blocks = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification)
        ->toArray()['blocks'];

    expect($blocks[2]['text']['text'])->toBe('*Hello* \\*not bold\\* — please review.');
});

it('SlackChannelHandler blocks format omits the section accessory when the type has no action', function (): void {
    config(['notifications-max.channels.slack.format' => 'blocks']);

    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.slack', ['name' => 'Alice']);

    $blocks = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification)
        ->toArray()['blocks'];

    expect($blocks[2]['type'])->toBe('section')
        ->and($blocks[2])->not->toHaveKey('accessory');
});

it('SlackChannelHandler blocks format omits the header block when the title resolves empty', function (): void {
    config(['notifications-max.channels.slack.format' => 'blocks']);
    config(['notifications' => [
        'demo.bodyonly' => [
            'title' => '',
            'body' => 'Just the body',
            'default_channels' => ['slack'],
            'allowed_channels' => ['slack'],
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();

    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.bodyonly');

    $blocks = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification)
        ->toArray()['blocks'];

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0])->toBe(['type' => 'divider'])
        ->and($blocks[1]['type'])->toBe('section')
        ->and($blocks[1]['text']['text'])->toBe('Just the body');
});

it('SlackChannelHandler blocks format emits a standalone actions block when there is a button but no body', function (): void {
    config(['notifications-max.channels.slack.format' => 'blocks']);
    config(['notifications' => [
        'demo.headeronly' => [
            'title' => 'Heads up',
            'body' => '',
            'default_channels' => ['slack'],
            'allowed_channels' => ['slack'],
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();

    $user = User::query()->create(['email' => 'a@x.test']);
    $notification = new GenericNotification('demo.headeronly', ['action_url' => 'https://app.test/go/9']);

    $blocks = (new SlackChannelHandler(app(NotificationContentResolver::class)))
        ->send($user, $notification)
        ->toArray()['blocks'];

    expect($blocks[0])->toBe(['type' => 'divider'])
        ->and($blocks[1]['type'])->toBe('header')
        ->and($blocks[1]['text']['text'])->toBe('Heads up')
        ->and($blocks[2]['type'])->toBe('actions')
        ->and($blocks[2]['elements'][0]['type'])->toBe('button')
        ->and($blocks[2]['elements'][0]['url'])->toBe('https://app.test/go/9')
        ->and($blocks[2]['elements'][0]['style'])->toBe('primary');
});
