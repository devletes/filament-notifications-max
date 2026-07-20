<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Defaults\PathActionUrlBuilder;
use Devletes\NotificationsMax\Services\NotificationActionUrlResolver;
use Devletes\NotificationsMax\Support\NotificationActionAddress;
use Devletes\NotificationsMax\Tests\Stubs\Resources\ModalTaskResource;
use Devletes\NotificationsMax\Tests\Stubs\Resources\PagedReportResource;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;

beforeEach(function (): void {
    config(['app.url' => 'https://app.example.test']);

    // One panel carrying both stub resources: 'tasks' has NO view page
    // (modal-on-list), 'reports' HAS one. Detection must diverge on
    // exactly that difference.
    //
    // Registered straight on the PanelRegistry singleton — the facade's
    // `Filament::registerPanel()` only queues a `resolving()` callback,
    // and the registry singleton is already resolved at boot in
    // Testbench, so facade-registered panels never actually land where
    // `Filament::getPanels()` looks. Detection needs the real registry.
    app(PanelRegistry::class)->register(
        Panel::make()
            ->id('employee')
            ->path('employee')
            ->resources([
                ModalTaskResource::class,
                PagedReportResource::class,
            ]),
    );

    $this->resolver = new NotificationActionUrlResolver(new PathActionUrlBuilder);
});

afterEach(function (): void {
    Filament::setCurrentPanel(null);
});

it('injects tableAction=view for a resource without a view page', function (): void {
    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 42,
        panels: ['employee'],
        preferredPanel: 'employee',
    );

    // No explicit tableAction on the address — detection finds the
    // resource, sees no 'view' page, and swaps to the query form so the
    // click opens the list page's view modal instead of 404ing.
    expect($this->resolver->resolve($address))
        ->toBe('https://app.example.test/employee/tasks?tableAction=view&tableActionRecord=42');
});

it('keeps the detail path for a resource with a view page', function (): void {
    $address = new NotificationActionAddress(
        resource: 'reports',
        recordId: 17,
        panels: ['employee'],
        preferredPanel: 'employee',
    );

    expect($this->resolver->resolve($address))
        ->toBe('https://app.example.test/employee/reports/17');
});

it('explicit address tableAction forces the query form even when a view page exists', function (): void {
    $address = new NotificationActionAddress(
        resource: 'reports',
        recordId: 17,
        panels: ['employee'],
        preferredPanel: 'employee',
        tableAction: 'view',
    );

    // Detection would keep the detail path ('reports' has a view page);
    // the explicit address field overrides it.
    expect($this->resolver->resolve($address))
        ->toBe('https://app.example.test/employee/reports?tableAction=view&tableActionRecord=17');
});

it('explicit address tableAction name wins over the detected default', function (): void {
    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 42,
        panels: ['employee'],
        preferredPanel: 'employee',
        tableAction: 'details',
    );

    // Detection would inject 'view' here; the explicit name is used
    // verbatim instead.
    expect($this->resolver->resolve($address))
        ->toBe('https://app.example.test/employee/tasks?tableAction=details&tableActionRecord=42');
});

it('falls back to the detail path for a resource slug the panel does not know', function (): void {
    $address = new NotificationActionAddress(
        resource: 'ghosts',
        recordId: 9,
        panels: ['employee'],
        preferredPanel: 'employee',
    );

    // No resource matches → detection stays out of the way (regression:
    // pre-feature URL shape, byte for byte).
    expect($this->resolver->resolve($address))
        ->toBe('https://app.example.test/employee/ghosts/9');
});

it('falls back to the detail path when the resolved panel is not registered', function (): void {
    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 9,
        panels: ['external'],
        preferredPanel: 'external',
    );

    // Panel lookup fails → detection aborts; the path builder falls back
    // to the panel id as the path segment, exactly as before the feature.
    expect($this->resolver->resolve($address))
        ->toBe('https://app.example.test/external/tasks/9');
});

it('keeps the detail path when auto_table_action is disabled', function (): void {
    config(['notifications-max.auto_table_action' => false]);

    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 42,
        panels: ['employee'],
        preferredPanel: 'employee',
    );

    expect($this->resolver->resolve($address))
        ->toBe('https://app.example.test/employee/tasks/42');
});

it('still honours an explicit address tableAction when auto_table_action is disabled', function (): void {
    config(['notifications-max.auto_table_action' => false]);

    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 42,
        panels: ['employee'],
        preferredPanel: 'employee',
        tableAction: 'view',
    );

    // The toggle gates DETECTION only — explicitly-addressed table
    // actions are the layer-1 feature and keep working.
    expect($this->resolver->resolve($address))
        ->toBe('https://app.example.test/employee/tasks?tableAction=view&tableActionRecord=42');
});
