<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Defaults\SubdomainActionUrlBuilder;

beforeEach(function (): void {
    config(['app.url' => 'https://example.test']);

    $this->builder = new SubdomainActionUrlBuilder;
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
