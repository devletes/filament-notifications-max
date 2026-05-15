<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Defaults\FilamentTenantResolver;
use Devletes\NotificationsMax\Tests\Stubs\Tenant;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->resolver = new FilamentTenantResolver;
});

it('returns null when no Filament tenant is bound', function (): void {
    expect($this->resolver->current())->toBeNull()
        ->and($this->resolver->currentId())->toBeNull();
});

it('current() returns whatever Filament::getTenant() returns', function (): void {
    $tenant = (new Tenant)->forceFill(['id' => 99]);

    Filament::setTenant($tenant, isQuiet: true);

    expect($this->resolver->current())->toBe($tenant)
        ->and($this->resolver->currentId())->toBe(99);

    // Cleanup so other tests don't see this binding.
    Filament::setTenant(null, isQuiet: true);
});

it('slugFor reads the tenant slug from a user-like object', function (): void {
    $tenant = (object) ['slug' => 'acme'];
    $user = (object) ['tenant' => $tenant];

    expect($this->resolver->slugFor($user))->toBe('acme');
});

it('slugFor returns null when the user has no tenant relation', function (): void {
    $user = (object) ['name' => 'no-tenant-user'];

    expect($this->resolver->slugFor($user))->toBeNull();
});

it('slugFor returns null when the tenant has no slug attribute', function (): void {
    $user = (object) ['tenant' => (object) ['name' => 'No-slug Tenant']];

    expect($this->resolver->slugFor($user))->toBeNull();
});

it('currentId coerces numeric string keys to int', function (): void {
    $tenant = (new Tenant)->forceFill(['id' => '42']);

    Filament::setTenant($tenant, isQuiet: true);

    expect($this->resolver->currentId())->toBe(42);

    Filament::setTenant(null, isQuiet: true);
});

// Non-numeric-key case omitted: Eloquent's default key type ('int') casts
// arbitrary primary-key values to int at attribute access time, so we
// can't realistically construct a "non-numeric key" through the model.
// In practice, hosts using non-int tenant keys override $keyType on their
// tenant model, and the resolver's is_numeric guard catches the remaining
// edge (e.g. UUID keys).
