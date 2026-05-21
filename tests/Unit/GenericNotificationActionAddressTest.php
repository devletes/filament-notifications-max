<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Support\NotificationActionAddress;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['app.url' => 'https://app.example.test']);
    // route() builds against URL::to() which reads from the active
    // request OR the URL facade's forced root. Without a request in
    // unit tests, default is http://localhost — pin both root and
    // scheme so assertions against `route()` output are stable.
    URL::forceRootUrl('https://app.example.test');
    URL::forceScheme('https');

    // Register both panels so route() resolves and PathActionUrlBuilder
    // produces panel-specific paths in the direct-URL fallback test.
    Filament::registerPanel(Panel::make()->id('admin')->path('admin'));
    Filament::registerPanel(Panel::make()->id('employee')->path('employee'));

    config(['notifications' => [
        // Single-panel, resource-bound type — exercises synthesis from
        // registry fields.
        'tasks.assigned' => [
            'title' => 'New task',
            'body' => 'For you',
            'panels' => ['admin', 'employee'],
            'target_panel' => 'employee',
            'action_resource' => 'tasks',
            'action_record_key' => 'task_id',
            'default_channels' => ['push'],
            'allowed_channels' => ['push', 'email'],
        ],
        // Polymorphic type — the dispatcher passes a fully-formed address
        // via context['action'] (mirrors how HRMS will handle approvals).
        'polymorphic.event' => [
            'title' => 'Polymorphic',
            'body' => 'Subject-driven URL',
            'default_channels' => ['push'],
            'allowed_channels' => ['push'],
        ],
        // No resource, no context.action → no address synthesizable.
        'orphan.type' => [
            'title' => 'Orphan',
            'body' => '...',
            'default_channels' => ['push'],
            'allowed_channels' => ['push'],
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();
});

afterEach(function (): void {
    Filament::setCurrentPanel(null);
});

it('synthesizes an address from registry fields when context omits one', function (): void {
    $n = new GenericNotification('tasks.assigned', [
        'task_id' => 42,
        'tenant_slug' => 'acme',
    ]);

    $address = $n->buildActionAddress($n->resolveType());

    expect($address)->toBeInstanceOf(NotificationActionAddress::class)
        ->and($address->resource)->toBe('tasks')
        ->and($address->recordId)->toBe(42)
        ->and($address->panels)->toBe(['admin', 'employee'])
        ->and($address->preferredPanel)->toBe('employee')
        ->and($address->tenantSlug)->toBe('acme');
});

it('hydrates an address from context[action] for polymorphic types', function (): void {
    $n = new GenericNotification('polymorphic.event', [
        'action' => [
            'resource' => 'leave-requests',
            'record_id' => 'req-99',
            'panels' => ['admin', 'employee'],
            'preferred_panel' => 'admin',
            'tenant_slug' => 'acme',
        ],
    ]);

    $address = $n->buildActionAddress($n->resolveType());

    expect($address)->toBeInstanceOf(NotificationActionAddress::class)
        ->and($address->resource)->toBe('leave-requests')
        ->and($address->recordId)->toBe('req-99')
        ->and($address->preferredPanel)->toBe('admin');
});

it('falls back to [targetPanel] when the registry omits panels', function (): void {
    // Re-declare the type without a `panels` key so the registry treats
    // it as omitted — config() with dot notation can preserve an existing
    // nested array when the new value collides with one already set.
    config(['notifications' => [
        'tasks.assigned' => [
            'title' => 'New task',
            'body' => 'For you',
            'target_panel' => 'employee',
            'action_resource' => 'tasks',
            'action_record_key' => 'task_id',
            'default_channels' => ['push'],
            'allowed_channels' => ['push', 'email'],
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();

    $n = new GenericNotification('tasks.assigned', ['task_id' => 7]);

    $address = $n->buildActionAddress($n->resolveType());

    expect($address->panels)->toBe(['employee']); // mirrors target_panel
});

it('returns null when the registry has no resource and no context address', function (): void {
    $n = new GenericNotification('orphan.type');

    expect($n->buildActionAddress($n->resolveType()))->toBeNull();
});

it('returns null when the record id is missing from context', function (): void {
    $n = new GenericNotification('tasks.assigned', []); // no task_id

    expect($n->buildActionAddress($n->resolveType()))->toBeNull();
});

it('buildLegacyActionUrl returns the redirect-route URL when an address exists', function (): void {
    $n = new GenericNotification('tasks.assigned', [
        'task_id' => 42,
        'tenant_slug' => 'acme',
    ]);
    // Laravel's NotificationSender stamps this before send(); set it
    // manually to mimic the moment toDatabase() runs.
    $n->id = (string) Str::uuid();

    $url = $n->buildLegacyActionUrl($n->resolveType());

    expect($url)->toBe("https://app.example.test/notifications-max/go/{$n->id}");
});

it('buildLegacyActionUrl falls back to direct URL when the redirect route is unregistered', function (): void {
    // Drop the redirect route to simulate a host that disabled it. This
    // is what `notifications-max.redirect_route.enabled = false` produces
    // at boot, without needing to refresh the application.
    \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
    $routes = \Illuminate\Support\Facades\Route::getRoutes();
    $reflection = new ReflectionClass($routes);
    $namedProp = $reflection->getProperty('nameList');
    $namedProp->setAccessible(true);
    $named = $namedProp->getValue($routes);
    unset($named['notifications-max.go']);
    $namedProp->setValue($routes, $named);

    $n = new GenericNotification('tasks.assigned', ['task_id' => 42]);
    $n->id = (string) Str::uuid();

    $url = $n->buildLegacyActionUrl($n->resolveType());

    // No redirect route → URL is built directly against the type's
    // target_panel (the legacy behaviour).
    expect($url)->toBe('https://app.example.test/employee/tasks/42');
});

it('buildLegacyActionUrl honours context.action_url verbatim', function (): void {
    $n = new GenericNotification('tasks.assigned', [
        'task_id' => 42,
        'action_url' => 'https://elsewhere.test/special-link',
    ]);
    $n->id = (string) Str::uuid();

    expect($n->buildLegacyActionUrl($n->resolveType()))
        ->toBe('https://elsewhere.test/special-link');
});

it('buildFilamentPayload persists the address in data.action', function (): void {
    $n = new GenericNotification('tasks.assigned', [
        'task_id' => 42,
        'tenant_slug' => 'acme',
    ]);

    $payload = $n->buildFilamentPayload();

    expect($payload)->toHaveKey('action')
        ->and($payload['action'])->toMatchArray([
            'resource' => 'tasks',
            'record_id' => 42,
            'panels' => ['admin', 'employee'],
            'preferred_panel' => 'employee',
            'tenant_slug' => 'acme',
        ]);
});

it('buildFilamentPayload omits data.action when no address can be synthesized', function (): void {
    $n = new GenericNotification('orphan.type');

    $payload = $n->buildFilamentPayload();

    expect($payload)->not->toHaveKey('action');
});
