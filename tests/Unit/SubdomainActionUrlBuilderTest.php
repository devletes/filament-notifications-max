<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Defaults\PathActionUrlBuilder;
use Devletes\NotificationsMax\Defaults\SubdomainActionUrlBuilder;
use Filament\Facades\Filament;
use Filament\Panel;

beforeEach(function (): void {
    config(['app.url' => 'https://example.test']);

    $this->builder = new SubdomainActionUrlBuilder(new PathActionUrlBuilder);
});

afterEach(function (): void {
    Filament::setCurrentPanel(null);
});

it('builds a subdomain-style URL when tenant_slug is in context', function (): void {
    $url = $this->builder->build('admin', 'requests', 42, ['tenant_slug' => 'acme']);

    expect($url)->toBe('https://acme.example.test/admin/requests/42');
});

it('honours app.domain when set independently of app.url', function (): void {
    config([
        'app.url' => 'https://app.example.test',
        'app.domain' => 'tenants.example.test',
    ]);

    $url = $this->builder->build('admin', 'requests', 42, ['tenant_slug' => 'acme']);

    expect($url)->toBe('https://acme.tenants.example.test/admin/requests/42');
});

it('falls back to PathActionUrlBuilder when tenant_slug is missing', function (): void {
    $url = $this->builder->build('admin', 'requests', 42, []);

    expect($url)->toBe('https://example.test/admin/requests/42');
});

it('falls back when tenant_slug is empty string', function (): void {
    $url = $this->builder->build('admin', 'requests', 42, ['tenant_slug' => '']);

    expect($url)->toBe('https://example.test/admin/requests/42');
});

it('emits the table-action query form when context carries table_action', function (): void {
    $url = $this->builder->build('employee', 'tasks', 42, [
        'tenant_slug' => 'acme',
        'table_action' => 'view',
    ]);

    expect($url)->toBe('https://acme.example.test/employee/tasks?tableAction=view&tableActionRecord=42');
});

it('emits the table-action query form at the domain root for an empty panel path', function (): void {
    // Root-mounted panel — the panel path segment vanishes and the query
    // must attach straight after the resource slug. Registered straight
    // on the PanelRegistry singleton: the facade's registerPanel() only
    // queues a resolving() callback, which never re-fires for the
    // already-resolved singleton in Testbench.
    app(\Filament\PanelRegistry::class)->register(Panel::make()->id('root')->path(''));

    $url = $this->builder->build('root', 'tasks', 42, [
        'tenant_slug' => 'acme',
        'table_action' => 'view',
    ]);

    expect($url)->toBe('https://acme.example.test/tasks?tableAction=view&tableActionRecord=42');
});

it('table_action rides through the path fallback when tenant_slug is missing', function (): void {
    // No tenant → subdomain builder delegates to the path builder with
    // the same $context, so the query form survives the fallback.
    $url = $this->builder->build('employee', 'tasks', 42, ['table_action' => 'view']);

    expect($url)->toBe('https://example.test/employee/tasks?tableAction=view&tableActionRecord=42');
});

it('ignores an empty table_action and keeps the record path form', function (): void {
    $url = $this->builder->build('employee', 'tasks', 42, [
        'tenant_slug' => 'acme',
        'table_action' => '',
    ]);

    expect($url)->toBe('https://acme.example.test/employee/tasks/42');
});

it('exposes the tenant host (no path) via baseUrl()', function (): void {
    expect($this->builder->baseUrl(['tenant_slug' => 'acme']))
        ->toBe('https://acme.example.test');
});

it('baseUrl() honours app.domain when set independently of app.url', function (): void {
    config([
        'app.url' => 'https://app.example.test',
        'app.domain' => 'tenants.example.test',
    ]);

    expect($this->builder->baseUrl(['tenant_slug' => 'acme']))
        ->toBe('https://acme.tenants.example.test');
});

it('baseUrl() returns null when tenant_slug is missing or empty', function (): void {
    expect($this->builder->baseUrl([]))->toBeNull();
    expect($this->builder->baseUrl(['tenant_slug' => '']))->toBeNull();
});
