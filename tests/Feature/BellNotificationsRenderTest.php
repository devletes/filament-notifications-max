<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Filament\Livewire\BellNotifications;
use Devletes\NotificationsMax\Tests\Stubs\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Panel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Mechanisms\DataStore;

beforeEach(function (): void {
    // Testbench provider-order artifact: our TestCase registers Livewire
    // before Filament, so Filament's `bind(DataStore, DataStoreOverride)`
    // drops Livewire's singleton DataStore *instance* and leaves a
    // non-shared bind — every app(DataStore) resolves a fresh, empty
    // store, and Livewire's error bag (stored per component) comes back
    // null mid-render. Pin one instance so component state survives the
    // render, as it does under a real app's provider order.
    $this->app->instance(DataStore::class, $this->app->make(DataStore::class));

    // The bell trigger renders a heroicon. Blade Icons isn't part of the
    // suite-wide provider set (nothing else renders SVGs), so register it
    // here; Filament adds its heroicon set via callAfterResolving as soon
    // as the icon factory resolves. Late registration wrinkle: the
    // provider's own callAfterResolving(ViewFactory) fires immediately
    // (views already resolved) and builds the factory BEFORE the provider
    // reaches its manifest binding — pre-bind the manifest so that
    // early build can resolve.
    $this->app->singleton(\BladeUI\Icons\IconsManifest::class, function ($app) {
        return new \BladeUI\Icons\IconsManifest(
            new \Illuminate\Filesystem\Filesystem,
            $app->bootstrapPath('cache/blade-icons.php'),
        );
    });
    $this->app->register(\BladeUI\Icons\BladeIconsServiceProvider::class);
    $this->app->register(\BladeUI\Heroicons\BladeHeroiconsServiceProvider::class);
});

afterEach(function (): void {
    Filament::setCurrentPanel(null);
});

it('renders a stored http action URL as https under a secure request', function (): void {
    config(['app.url' => 'https://app.example.test']);

    // Render the bell component through a real kernel dispatch (web
    // middleware gives Livewire its session-backed error bag) at an
    // absolute https URL, so the blade's request() is genuinely secure.
    // Livewire::test() is no help here: it dispatches its synthetic
    // request against the console-captured http://localhost base with
    // middleware disabled. The panel is registered inside the route
    // closure because facade registrations made in the test method body
    // aren't visible to Filament during the dispatched request.
    Route::middleware('web')->get('/bell-render-test', function (): string {
        $panel = Panel::make()->id('admin')->path('admin')->default();

        Filament::registerPanel($panel);
        Filament::setCurrentPanel($panel);

        return Blade::render('@livewire($component)', [
            'component' => BellNotifications::class,
        ]);
    });

    $user = User::query()->create(['name' => 'Bell', 'email' => 'bell@example.test']);

    // A legacy row whose action URL was baked with an http APP_URL in
    // queue context. getDatabaseMessage() stamps format=filament, which
    // the bell component's query filters on.
    $row = new DatabaseNotification([
        'id' => (string) Str::uuid(),
        'type' => 'TestNotification',
        'data' => FilamentNotification::make()
            ->title('Leave request approved')
            ->actions([
                Action::make('view')->url('http://app.example.test/admin/tasks/17'),
            ])
            ->getDatabaseMessage(),
    ]);
    $row->notifiable_type = $user::class;
    $row->notifiable_id = $user->getKey();
    $row->save();

    $this->actingAs($user)
        ->get('https://app.example.test/bell-render-test')
        ->assertOk()
        ->assertSee('https://app.example.test/admin/tasks/17', escape: false)
        ->assertDontSee('http://app.example.test/admin/tasks/17', escape: false)
        // Link clicks dismiss the slide-over (capture phase — wire:navigate
        // stops propagation, so a bubble listener would never fire).
        ->assertSee('x-on:click.capture', escape: false)
        ->assertSee("\$dispatch('close-modal', { id: 'database-notifications' })", escape: false);
});
