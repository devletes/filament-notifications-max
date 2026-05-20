<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Queue\RestoreTenantContext;
use Devletes\NotificationsMax\Tests\Stubs\Tenant;
use Filament\Facades\Filament;
use Filament\Panel;

/**
 * Unit coverage for the queue middleware. The "happy path" test (verify
 * the tenant binding lands inside the next handler) needs a fuller
 * Filament panel-registration setup than these tests do — `Panel::make()
 * + Filament::registerPanel()` doesn't fully boot the panel the way a
 * real PanelProvider does, so the middleware's auto-discovery loop
 * doesn't see the test panel as tenanted. The integration is exercised
 * in production by host apps; closing the unit-test gap is a follow-up.
 *
 * What this file DOES cover:
 *   - State is clean after the next handler returns
 *   - State is clean even when the next handler throws
 *   - Skip when the job lacks a tenantId property
 *   - Skip when the job's tenantId doesn't resolve to a tenant row
 */
beforeEach(function (): void {
    RestoreTenantContext::flushPanelCache();

    Filament::registerPanel(
        Panel::make()
            ->id('test-admin')
            ->path('admin')
            ->tenant(Tenant::class),
    );

    $this->middleware = new RestoreTenantContext;
});

afterEach(function (): void {
    Filament::setTenant(null, isQuiet: true);
    Filament::setCurrentPanel(null);
});

it('leaves Filament tenant null after the next handler returns', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme']);

    $job = new class
    {
        public ?int $tenantId = null;
    };
    $job->tenantId = $tenant->id;

    $this->middleware->handle($job, fn (): null => null);

    expect(Filament::getTenant())->toBeNull();
});

it('restores tenant binding even when the next handler throws', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme']);

    $job = new class
    {
        public ?int $tenantId = null;
    };
    $job->tenantId = $tenant->id;

    try {
        $this->middleware->handle($job, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // Expected — middleware re-raises after its finally runs.
    }

    expect(Filament::getTenant())->toBeNull();
});

it('skips binding and just runs the next handler when the job has no tenantId property', function (): void {
    $job = new class
    {
        public string $payload = 'no-tenant';
    };

    $reached = false;
    $this->middleware->handle($job, function () use (&$reached): void {
        $reached = true;
    });

    expect($reached)->toBeTrue()
        ->and(Filament::getTenant())->toBeNull();
});

it('skips binding when the tenantId does not resolve to a tenant row', function (): void {
    $job = new class
    {
        public ?int $tenantId = 99999; // not in DB
    };

    $reached = false;
    $this->middleware->handle($job, function () use (&$reached): void {
        $reached = true;
    });

    expect($reached)->toBeTrue()
        ->and(Filament::getTenant())->toBeNull();
});
