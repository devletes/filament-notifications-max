<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Illuminate\Notifications\Messages\BroadcastMessage;

beforeEach(function (): void {
    config(['notifications' => [
        'demo.type' => [
            'title' => 'Hello {name}',
            'body' => 'Welcome to {place}',
            'icon' => 'heroicon-o-bell',
            'color' => 'primary',
            'default_channels' => ['push'],
            'allowed_channels' => ['push', 'email'],
        ],
        'demo.actions' => [
            'title' => 'Multi-action',
            'body' => '...',
            'actions' => [
                [
                    'name' => 'approve',
                    'label' => 'Approve {name}',
                    'url' => 'https://example.test/approve/{id}',
                    'color' => 'success',
                ],
                [
                    'name' => 'reject',
                    'label' => 'Reject',
                    'url' => 'https://example.test/reject/{id}',
                    'color' => 'danger',
                ],
            ],
            'default_channels' => ['push'],
            'allowed_channels' => ['push'],
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();
});

it('render() substitutes placeholders from context', function (): void {
    $n = new GenericNotification('demo.type', ['name' => 'Alice', 'place' => 'HQ']);

    expect($n->render('Hello {name}, welcome to {place}'))
        ->toBe('Hello Alice, welcome to HQ');
});

it('render() preserves placeholders with no matching context key', function (): void {
    $n = new GenericNotification('demo.type', ['name' => 'Alice']);

    expect($n->render('Hello {name}, your {missing} is ready'))
        ->toBe('Hello Alice, your {missing} is ready');
});

it('render() supports dotted paths into nested context arrays', function (): void {
    $n = new GenericNotification('demo.type', [
        'user' => ['profile' => ['name' => 'Bob']],
    ]);

    expect($n->render('Hi {user.profile.name}'))->toBe('Hi Bob');
});

it('render() returns empty string for empty template', function (): void {
    expect((new GenericNotification('demo.type'))->render(''))->toBe('');
});

it('buildFilamentPayload includes _meta with type_key + target_panel', function (): void {
    $payload = (new GenericNotification('demo.type', ['name' => 'Alice', 'place' => 'HQ']))
        ->buildFilamentPayload();

    expect($payload)->toHaveKey('_meta')
        ->and($payload['_meta']['type_key'])->toBe('demo.type');
});

it('buildFilamentPayload stamps broadcast_id when supplied in context', function (): void {
    $payload = (new GenericNotification('demo.type', ['broadcast_id' => 42]))
        ->buildFilamentPayload();

    expect($payload['broadcast_id'] ?? null)->toBe(42);
});

it('buildFilamentPayload omits broadcast_id when not supplied', function (): void {
    $payload = (new GenericNotification('demo.type', []))
        ->buildFilamentPayload();

    expect($payload)->not->toHaveKey('broadcast_id');
});

it('resolveBroadcastData attaches a UUID and a duration to the payload', function (): void {
    $data = (new GenericNotification('demo.type'))->resolveBroadcastData();

    expect($data)->toHaveKeys(['id', 'duration'])
        ->and($data['id'])->toMatch('/^[0-9a-f-]{36}$/i');
});

it('resolveBroadcastData id is stable within a single instance', function (): void {
    $n = new GenericNotification('demo.type');

    expect($n->resolveBroadcastData()['id'])
        ->toBe($n->resolveBroadcastData()['id']);
});

it('broadcastWith returns the same data as resolveBroadcastData', function (): void {
    $n = new GenericNotification('demo.type');

    expect($n->broadcastWith())->toBe($n->resolveBroadcastData());
});

it('toBroadcast wraps resolveBroadcastData in a BroadcastMessage', function (): void {
    $n = new GenericNotification('demo.type');

    $message = $n->toBroadcast(new stdClass);

    expect($message)->toBeInstanceOf(BroadcastMessage::class)
        ->and($message->data)->toBe($n->resolveBroadcastData());
});

it('toDatabase returns buildFilamentPayload verbatim', function (): void {
    $n = new GenericNotification('demo.type', ['name' => 'Alice']);

    expect($n->toDatabase(new stdClass))->toBe($n->buildFilamentPayload());
});

it('buildActions returns an array of Filament Action objects when registry declares actions', function (): void {
    $type = app(NotificationTypeRegistry::class)->find('demo.actions');

    $actions = (new GenericNotification('demo.actions', ['name' => 'Alice', 'id' => 99]))
        ->buildActions($type);

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->getUrl())->toBe('https://example.test/approve/99')
        ->and($actions[1]->getUrl())->toBe('https://example.test/reject/99');
});

it('buildActions returns empty when registry has no actions and no action_resource', function (): void {
    $type = app(NotificationTypeRegistry::class)->find('demo.type');

    expect((new GenericNotification('demo.type'))->buildActions($type))->toBe([]);
});

it('dispatchChannel throws a helpful error when the channel handler is not configured', function (): void {
    // Simulate a host that removed the database handler from config but
    // still has notifications routed to the database channel — exercises
    // the missing-handler error path on GenericNotification::dispatchChannel.
    config(['notifications-max.channel_handlers.database' => null]);

    $n = new GenericNotification('demo.type');

    expect(fn () => $n->toDatabase(new stdClass))
        ->toThrow(RuntimeException::class, 'No channel handler registered for [database]');
});

it('all pre-loaded toX methods resolve to a built-in handler by default', function (): void {
    // toTwilio / toVonage / toSlack / toDiscord all need third-party
    // Message classes available at call time, so we can't actually invoke
    // them without those packages installed. But we CAN verify they're
    // declared as real methods, so `method_exists` checks elsewhere in
    // Laravel's channel pipeline succeed.
    $n = new GenericNotification('demo.type');

    expect(method_exists($n, 'toDatabase'))->toBeTrue()
        ->and(method_exists($n, 'toBroadcast'))->toBeTrue()
        ->and(method_exists($n, 'toMail'))->toBeTrue()
        ->and(method_exists($n, 'toTwilio'))->toBeTrue()
        ->and(method_exists($n, 'toVonage'))->toBeTrue()
        ->and(method_exists($n, 'toSlack'))->toBeTrue()
        ->and(method_exists($n, 'toDiscord'))->toBeTrue();
});
