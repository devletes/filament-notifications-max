<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Support\ActionUrlSchemeNormalizer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['app.url' => 'http://hrms.test']);
});

/**
 * Helper: a request whose scheme/host come straight from the URI, exactly
 * as Symfony derives them for a real client hit.
 */
function secureRequestTo(string $uri = 'https://devletes.hrms.test/admin'): Request
{
    return Request::create($uri);
}

it('upgrades http to https when the URL host equals the current request host', function (): void {
    $url = ActionUrlSchemeNormalizer::normalize(
        'http://devletes.hrms.test/notifications-max/go/abc?from=admin',
        secureRequestTo('https://devletes.hrms.test/admin'),
    );

    expect($url)->toBe('https://devletes.hrms.test/notifications-max/go/abc?from=admin');
});

it('upgrades http when the URL host equals the app.url host', function (): void {
    // Request arrives on a tenant subdomain; the stored URL targets the apex
    // (= app.url host). Still ours.
    $url = ActionUrlSchemeNormalizer::normalize(
        'http://hrms.test/admin/tasks/17',
        secureRequestTo('https://devletes.hrms.test/admin'),
    );

    expect($url)->toBe('https://hrms.test/admin/tasks/17');
});

it('upgrades http when the URL host is a subdomain of the app.url host', function (): void {
    // Cross-tenant link: request on one subdomain, stored URL on another.
    // Both hang off the app.url host, so both are ours.
    $url = ActionUrlSchemeNormalizer::normalize(
        'http://other.hrms.test/employee/tasks/17',
        secureRequestTo('https://devletes.hrms.test/admin'),
    );

    expect($url)->toBe('https://other.hrms.test/employee/tasks/17');
});

it('matches hosts case-insensitively', function (): void {
    $url = ActionUrlSchemeNormalizer::normalize(
        'http://DEVLETES.HRMS.TEST/admin/tasks/17',
        secureRequestTo('https://devletes.hrms.test/admin'),
    );

    expect($url)->toBe('https://DEVLETES.HRMS.TEST/admin/tasks/17');
});

it('leaves external hosts untouched', function (): void {
    // A lookalike suffix without the dot boundary is external too:
    // evil-hrms.test does not end with ".hrms.test".
    expect(ActionUrlSchemeNormalizer::normalize('http://elsewhere.example.com/x', secureRequestTo()))
        ->toBe('http://elsewhere.example.com/x')
        ->and(ActionUrlSchemeNormalizer::normalize('http://evil-hrms.test/x', secureRequestTo()))
        ->toBe('http://evil-hrms.test/x');
});

it('never downgrades https input', function (): void {
    expect(ActionUrlSchemeNormalizer::normalize('https://devletes.hrms.test/x', secureRequestTo()))
        ->toBe('https://devletes.hrms.test/x');
});

it('leaves relative and protocol-relative URLs untouched', function (): void {
    expect(ActionUrlSchemeNormalizer::normalize('/admin/tasks/17', secureRequestTo()))
        ->toBe('/admin/tasks/17')
        ->and(ActionUrlSchemeNormalizer::normalize('//devletes.hrms.test/x', secureRequestTo()))
        ->toBe('//devletes.hrms.test/x');
});

it('does nothing when the current request is not secure', function (): void {
    $url = ActionUrlSchemeNormalizer::normalize(
        'http://devletes.hrms.test/x',
        Request::create('http://devletes.hrms.test/admin'),
    );

    expect($url)->toBe('http://devletes.hrms.test/x');
});

it('does nothing without a request (CLI context)', function (): void {
    expect(ActionUrlSchemeNormalizer::normalize('http://devletes.hrms.test/x', null))
        ->toBe('http://devletes.hrms.test/x');
});

it('normalizes action URLs on a hydrated notification, leaving external ones alone', function (): void {
    $notification = FilamentNotification::make()
        ->title('Test')
        ->actions([
            Action::make('view')->url('http://devletes.hrms.test/admin/tasks/17', shouldOpenInNewTab: true),
            Action::make('docs')->url('http://elsewhere.example.com/manual'),
        ]);

    ActionUrlSchemeNormalizer::normalizeNotification($notification, secureRequestTo());

    [$view, $docs] = $notification->getActions();

    expect($view->getUrl())->toBe('https://devletes.hrms.test/admin/tasks/17')
        // The rewrite must not clobber sibling URL config.
        ->and($view->shouldOpenUrlInNewTab())->toBeTrue()
        ->and($docs->getUrl())->toBe('http://elsewhere.example.com/manual');
});

it('normalizes actions nested inside an ActionGroup', function (): void {
    $notification = FilamentNotification::make()
        ->title('Test')
        ->actions([
            ActionGroup::make([
                Action::make('view')->url('http://devletes.hrms.test/admin/tasks/17'),
            ]),
        ]);

    ActionUrlSchemeNormalizer::normalizeNotification($notification, secureRequestTo());

    /** @var ActionGroup $group */
    $group = $notification->getActions()[0];

    expect($group->getFlatActions()['view']->getUrl())
        ->toBe('https://devletes.hrms.test/admin/tasks/17');
});

it('heals a stored row along the same path the bell dropdown renders through', function (): void {
    // Mirrors the display seam end-to-end minus the Livewire chrome: a row
    // whose action URL was baked as http (queue-context APP_URL) is rebuilt
    // via Notification::fromDatabase() — exactly what the overridden
    // database-notifications view does — then normalized for the current
    // https request.
    $row = new DatabaseNotification([
        'id' => (string) Str::uuid(),
        'type' => 'TestNotification',
        'data' => FilamentNotification::make()
            ->title('Leave request approved')
            ->actions([
                Action::make('view')->url('http://devletes.hrms.test/notifications-max/go/abc'),
            ])
            ->getDatabaseMessage(),
    ]);

    $notification = ActionUrlSchemeNormalizer::normalizeNotification(
        FilamentNotification::fromDatabase($row),
        secureRequestTo('https://devletes.hrms.test/admin'),
    );

    expect($notification->getActions()[0]->getUrl())
        ->toBe('https://devletes.hrms.test/notifications-max/go/abc');
});
