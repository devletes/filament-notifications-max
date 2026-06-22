<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Tests\Stubs\RestrictedUser;
use Devletes\NotificationsMax\Tests\Stubs\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['app.url' => 'https://app.example.test']);

    Filament::registerPanel(Panel::make()->id('admin')->path('admin'));
    Filament::registerPanel(Panel::make()->id('employee')->path('employee'));
});

afterEach(function (): void {
    Filament::setCurrentPanel(null);
});

/**
 * Helper: insert a notification row directly so we don't have to spin up
 * the dispatcher for these HTTP-level tests.
 *
 * @param  array<string, mixed>  $data
 */
function makeNotificationRow(User $user, array $data, ?string $id = null): DatabaseNotification
{
    $row = new DatabaseNotification([
        'id' => $id ?? (string) Str::uuid(),
        'type' => 'TestNotification',
        'data' => $data,
        'read_at' => null,
    ]);

    $row->notifiable_type = $user::class;
    $row->notifiable_id = $user->getKey();
    $row->save();

    return $row;
}

it('redirects 302 to the panel matching ?from= when accessible', function (): void {
    $user = RestrictedUser::query()->create(['name' => 'Hybrid', 'email' => 'h@example.test']);
    $user->allowedPanels = ['admin', 'employee'];

    $row = makeNotificationRow($user, [
        'action' => [
            'resource' => 'tasks',
            'record_id' => 17,
            'panels' => ['admin', 'employee'],
            'preferred_panel' => 'admin',
        ],
    ]);

    $this->actingAs($user)
        ->get("/notifications-max/go/{$row->id}?from=employee")
        ->assertRedirect('https://app.example.test/employee/tasks/17');
});

it('falls back to preferred panel when ?from= is missing (mail-style click)', function (): void {
    $user = User::query()->create(['name' => 'Mail', 'email' => 'm@example.test']);

    $row = makeNotificationRow($user, [
        'action' => [
            'resource' => 'surveys',
            'record_id' => 5,
            'panels' => ['admin', 'employee'],
            'preferred_panel' => 'employee',
        ],
    ]);

    $this->actingAs($user)
        ->get("/notifications-max/go/{$row->id}")
        ->assertRedirect('https://app.example.test/employee/surveys/5');
});

it('uses the baked url for legacy rows that lack a structured action payload', function (): void {
    $user = User::query()->create(['name' => 'Legacy', 'email' => 'l@example.test']);

    $row = makeNotificationRow($user, [
        // No 'action' key — the dispatcher baked the URL into 'url' before
        // this feature shipped. Make sure those still click through.
        'url' => 'https://elsewhere.example.test/tasks/99',
    ]);

    $this->actingAs($user)
        ->get("/notifications-max/go/{$row->id}")
        ->assertRedirect('https://elsewhere.example.test/tasks/99');
});

it('returns 404 for a notification belonging to a different user', function (): void {
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'o@example.test']);
    $stranger = User::query()->create(['name' => 'Stranger', 'email' => 's@example.test']);

    $row = makeNotificationRow($owner, [
        'action' => [
            'resource' => 'tasks',
            'record_id' => 17,
            'panels' => ['admin'],
            'preferred_panel' => 'admin',
        ],
    ]);

    $this->actingAs($stranger)
        ->get("/notifications-max/go/{$row->id}")
        ->assertNotFound();
});

it('returns 404 for a non-existent notification id', function (): void {
    $user = User::query()->create(['name' => 'Empty', 'email' => 'e@example.test']);

    $this->actingAs($user)
        ->get('/notifications-max/go/'.Str::uuid())
        ->assertNotFound();
});

it('marks the notification read as the click passes through', function (): void {
    $user = User::query()->create(['name' => 'Reader', 'email' => 'reader@example.test']);

    $row = makeNotificationRow($user, [
        'action' => [
            'resource' => 'tasks',
            'record_id' => 17,
            'panels' => ['admin', 'employee'],
            'preferred_panel' => 'employee',
        ],
    ]);

    expect($row->read_at)->toBeNull();

    $this->actingAs($user)
        ->get("/notifications-max/go/{$row->id}")
        ->assertRedirect('https://app.example.test/employee/tasks/17');

    expect($row->fresh()->read_at)->not->toBeNull();
});

it('leaves read state untouched when redirect_route.mark_read is disabled', function (): void {
    config(['notifications-max.redirect_route.mark_read' => false]);

    $user = User::query()->create(['name' => 'Skipper', 'email' => 'skipper@example.test']);

    $row = makeNotificationRow($user, [
        'action' => [
            'resource' => 'tasks',
            'record_id' => 21,
            'panels' => ['employee'],
            'preferred_panel' => 'employee',
        ],
    ]);

    $this->actingAs($user)
        ->get("/notifications-max/go/{$row->id}")
        ->assertRedirect('https://app.example.test/employee/tasks/21');

    expect($row->fresh()->read_at)->toBeNull();
});

it('registers the redirect route when redirect_route.enabled is true', function (): void {
    expect(Route::has('notifications-max.go'))->toBeTrue();
});

// Note: Referer-driven panel inference is covered by
// `NotificationRedirectController::matchRefererToPanel()` and unit-tested
// in NotificationRedirectControllerMatchRefererTest. Filament's panel
// registry isn't visible through the HTTP test kernel in Testbench
// (panels registered via the facade in beforeEach don't survive the
// request dispatch), so the HTTP-level integration is exercised via the
// `?from=` query path here; the static helper test covers the parsing
// rules.
