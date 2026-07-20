<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Defaults\PathActionUrlBuilder;
use Devletes\NotificationsMax\Services\NotificationActionUrlResolver;
use Devletes\NotificationsMax\Support\NotificationActionAddress;
use Devletes\NotificationsMax\Tests\Stubs\RestrictedUser;
use Filament\Facades\Filament;
use Filament\Panel;

beforeEach(function (): void {
    config(['app.url' => 'https://app.example.test']);

    // Two panels registered with different paths so we can verify the
    // resolver delegates the chosen panel to the URL builder correctly.
    // Using non-empty paths for both sidesteps Filament's empty-path
    // edge cases in Testbench — the resolver's responsibility is panel
    // *choice*, and that's visible from which path segment appears.
    Filament::registerPanel(Panel::make()->id('admin')->path('admin'));
    Filament::registerPanel(Panel::make()->id('employee')->path('employee'));

    $this->resolver = new NotificationActionUrlResolver(new PathActionUrlBuilder);
});

afterEach(function (): void {
    Filament::setCurrentPanel(null);
});

it('prefers the current panel when it is in the address panels list and accessible', function (): void {
    $user = new RestrictedUser();
    $user->allowedPanels = ['admin', 'employee'];

    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 17,
        panels: ['admin', 'employee'],
        preferredPanel: 'admin',
    );

    $url = $this->resolver->resolve($address, $user, fromPanelId: 'employee');

    expect($url)->toBe('https://app.example.test/employee/tasks/17');
});

it('falls back to preferred panel when current panel is missing or inaccessible', function (): void {
    $user = new RestrictedUser();
    $user->allowedPanels = ['admin'];

    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 17,
        panels: ['admin', 'employee'],
        preferredPanel: 'admin',
    );

    // 'employee' is in the panels list but the user can't access it.
    $url = $this->resolver->resolve($address, $user, fromPanelId: 'employee');

    expect($url)->toBe('https://app.example.test/admin/tasks/17');
});

it('falls back to first accessible panel when preferred is unset or inaccessible', function (): void {
    $user = new RestrictedUser();
    $user->allowedPanels = ['employee'];

    $address = new NotificationActionAddress(
        resource: 'surveys',
        recordId: 5,
        panels: ['admin', 'employee'],
        preferredPanel: 'admin',
    );

    $url = $this->resolver->resolve($address, $user);

    // admin is preferred but inaccessible; employee is the first accessible.
    expect($url)->toBe('https://app.example.test/employee/surveys/5');
});

it('uses preferred as last-resort when no panel is accessible', function (): void {
    $user = new RestrictedUser();
    $user->allowedPanels = []; // no panel accessible

    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 17,
        panels: ['admin', 'employee'],
        preferredPanel: 'admin',
    );

    $url = $this->resolver->resolve($address, $user);

    // Last-resort: even though the user can't access admin, the address's
    // preferred panel produces a well-formed link that surfaces the 403
    // at click time rather than silently dropping the action button.
    expect($url)->toBe('https://app.example.test/admin/tasks/17');
});

it('returns null when no panel can be chosen', function (): void {
    $user = new RestrictedUser();
    $user->allowedPanels = []; // no panel accessible
    // No preferred panel and no candidates accessible — give up.

    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 17,
        panels: ['admin', 'employee'],
        preferredPanel: null,
    );

    $url = $this->resolver->resolve($address, $user);

    expect($url)->toBeNull();
});

it('treats an empty panels list as "any panel is a candidate"', function (): void {
    $user = new RestrictedUser();
    $user->allowedPanels = ['employee'];

    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 17,
        panels: [],
        preferredPanel: 'admin', // not accessible, but valid as a candidate
    );

    // panels=[] means anything goes — preferred 'admin' is accessible? no.
    // So step 2 fails; step 3 has no panels to iterate; step 4 returns
    // preferred regardless.
    $url = $this->resolver->resolve($address, $user);

    expect($url)->toBe('https://app.example.test/admin/tasks/17');
});

it('falls through to preferred for mail context (no current panel, no user)', function (): void {
    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 17,
        panels: ['admin', 'employee'],
        preferredPanel: 'employee',
    );

    // No user → access check bypassed; no fromPanelId → step 1 skipped.
    // Step 2: preferred 'employee' is in panels → use it.
    $url = $this->resolver->resolve($address, user: null, fromPanelId: null);

    expect($url)->toBe('https://app.example.test/employee/tasks/17');
});

it('users without FilamentUser contract are allowed everywhere', function (): void {
    $user = new \Devletes\NotificationsMax\Tests\Stubs\User();

    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 17,
        panels: ['admin', 'employee'],
        preferredPanel: 'admin',
    );

    // Stub user doesn't implement FilamentUser → mirrors Filament's default-
    // allow behaviour. fromPanel 'employee' is in the list and "accessible".
    $url = $this->resolver->resolve($address, $user, fromPanelId: 'employee');

    expect($url)->toBe('https://app.example.test/employee/tasks/17');
});

it('resolves the table-action query form when the address carries one', function (): void {
    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 17,
        panels: ['admin', 'employee'],
        preferredPanel: 'employee',
        tableAction: 'view',
    );

    expect($this->resolver->resolve($address))
        ->toBe('https://app.example.test/employee/tasks?tableAction=view&tableActionRecord=17');
});

it('forwards table_action into the builder context alongside tenant_slug', function (): void {
    $capturing = new class implements \Devletes\NotificationsMax\Contracts\ActionUrlBuilder
    {
        /** @var array<string, mixed> */
        public array $context = [];

        public function build(
            string $panelId,
            string $resourceSlug,
            int|string $recordId,
            array $context = [],
        ): string {
            $this->context = $context;

            return 'https://captured.example.test';
        }
    };

    $resolver = new NotificationActionUrlResolver($capturing);

    $resolver->resolve(new NotificationActionAddress(
        resource: 'tasks',
        recordId: 17,
        panels: ['employee'],
        preferredPanel: 'employee',
        tenantSlug: 'acme',
        tableAction: 'view',
    ));

    expect($capturing->context)->toBe([
        'tenant_slug' => 'acme',
        'table_action' => 'view',
    ]);

    // Null table action → the key is filtered out entirely, matching the
    // pre-feature context shape third-party builders already receive.
    $resolver->resolve(new NotificationActionAddress(
        resource: 'tasks',
        recordId: 17,
        panels: ['employee'],
        preferredPanel: 'employee',
        tenantSlug: 'acme',
    ));

    expect($capturing->context)->toBe(['tenant_slug' => 'acme']);
});

it('exposes resolvePanel for callers that need the chosen panel id', function (): void {
    $user = new RestrictedUser();
    $user->allowedPanels = ['admin'];

    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 17,
        panels: ['admin', 'employee'],
        preferredPanel: 'employee',
    );

    expect($this->resolver->resolvePanel($address, $user))->toBe('admin');
});
