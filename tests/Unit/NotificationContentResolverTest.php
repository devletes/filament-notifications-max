<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Models\NotificationTypeOverride;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Services\NotificationContentResolver;

beforeEach(function (): void {
    config(['notifications' => [
        'invoice.paid' => [
            'category' => 'billing',
            'title' => 'Invoice {invoice_id} paid',
            'body' => 'Thanks {customer_name}',
            'content' => [
                'push' => [
                    'title' => 'Paid: {invoice_id}',
                    'body' => 'Nice — {customer_name} paid up',
                ],
                'email' => [
                    'subject' => 'Payment received for #{invoice_id}',
                    'body' => '<p>Hello {customer_name}</p>',
                    'template' => 'default',
                ],
            ],
            'allowed_channels' => ['push', 'email'],
        ],
        'audit.locked' => [
            'category' => 'compliance',
            'title' => 'Records locked',
            'body' => 'Compliance action taken',
            'allowed_channels' => ['push', 'email'],
            'mandatory' => true,
        ],
    ]]);

    // The registry is a container singleton — flush its in-process cache
    // so the config we just set is what it sees on first call.
    app(NotificationTypeRegistry::class)->flush();

    $this->resolver = new NotificationContentResolver(app(NotificationTypeRegistry::class));
});

it('reads channel content from config when content_source = config', function (): void {
    config(['notifications-max.content_source' => 'config']);

    $push = $this->resolver->contentFor('invoice.paid', 'push', tenantId: null);

    expect($push)->toBe([
        'title' => 'Paid: {invoice_id}',
        'body' => 'Nice — {customer_name} paid up',
    ]);
});

it('falls back to top-level title/body when channel config is missing the field', function (): void {
    config([
        'notifications-max.content_source' => 'config',
        'notifications' => [
            'minimal' => [
                'title' => 'Top-level title',
                'body' => 'Top-level body',
                'allowed_channels' => ['push'],
            ],
        ],
    ]);

    $push = $this->resolver->contentFor('minimal', 'push', tenantId: null);

    expect($push)->toBe([
        'title' => 'Top-level title',
        'body' => 'Top-level body',
    ]);
});

it('maps top-level title onto email subject as a back-compat fallback', function (): void {
    config([
        'notifications-max.content_source' => 'config',
        'notifications' => [
            'no.email.content' => [
                'title' => 'Generic title',
                'body' => 'Generic body',
                'allowed_channels' => ['email'],
            ],
        ],
    ]);

    $email = $this->resolver->contentFor('no.email.content', 'email', tenantId: null);

    expect($email['subject'])->toBe('Generic title')
        ->and($email['body'])->toBe('Generic body');
});

it('returns the channel registry shape — only declared fields, no extras', function (): void {
    config(['notifications-max.content_source' => 'config']);

    $email = $this->resolver->contentFor('invoice.paid', 'email', tenantId: null);

    // The 'email' channel declares subject + body + template; no 'title'.
    expect($email)->toHaveKeys(['subject', 'body', 'template'])
        ->and($email)->not->toHaveKey('title');
});

it('returns an empty array for a channel with no registry entry', function (): void {
    config(['notifications-max.content_source' => 'config']);

    expect($this->resolver->contentFor('invoice.paid', 'unregistered', tenantId: null))
        ->toBe([]);
});

it('reads override values from the database when content_source = database', function (): void {
    config(['notifications-max.content_source' => 'database']);

    NotificationTypeOverride::query()->create([
        'tenant_id' => null,
        'type_key' => 'invoice.paid',
        'channel_content' => [
            'push' => ['title' => 'CUSTOM TITLE', 'body' => 'CUSTOM BODY'],
        ],
    ]);

    expect($this->resolver->contentFor('invoice.paid', 'push', tenantId: null))
        ->toBe(['title' => 'CUSTOM TITLE', 'body' => 'CUSTOM BODY']);
});

it('falls back to config when DB override field is missing', function (): void {
    config(['notifications-max.content_source' => 'database']);

    NotificationTypeOverride::query()->create([
        'tenant_id' => null,
        'type_key' => 'invoice.paid',
        // Override only the title; body falls back to channel config.
        'channel_content' => [
            'push' => ['title' => 'Overridden'],
        ],
    ]);

    expect($this->resolver->contentFor('invoice.paid', 'push', tenantId: null))
        ->toBe([
            'title' => 'Overridden',
            'body' => 'Nice — {customer_name} paid up',
        ]);
});

it('falls back to config when DB override field is the empty string', function (): void {
    config(['notifications-max.content_source' => 'database']);

    NotificationTypeOverride::query()->create([
        'tenant_id' => null,
        'type_key' => 'invoice.paid',
        'channel_content' => ['push' => ['title' => '', 'body' => 'Real body']],
    ]);

    expect($this->resolver->contentFor('invoice.paid', 'push', tenantId: null))
        ->toBe([
            'title' => 'Paid: {invoice_id}', // config value, empty string ignored
            'body' => 'Real body',
        ]);
});

it('falls back to config when the override row is missing entirely', function (): void {
    config(['notifications-max.content_source' => 'database']);

    expect($this->resolver->contentFor('invoice.paid', 'push', tenantId: null))
        ->toBe([
            'title' => 'Paid: {invoice_id}',
            'body' => 'Nice — {customer_name} paid up',
        ]);
});

it('memoises override lookups per (tenant, type) within a request', function (): void {
    config(['notifications-max.content_source' => 'database']);

    NotificationTypeOverride::query()->create([
        'tenant_id' => null,
        'type_key' => 'invoice.paid',
        'channel_content' => ['push' => ['title' => 'V1', 'body' => 'B1']],
    ]);

    $this->resolver->contentFor('invoice.paid', 'push', tenantId: null);

    // Mutate the row directly; without flushCache, the resolver should
    // still return the originally-loaded values.
    NotificationTypeOverride::query()->update([
        'channel_content' => ['push' => ['title' => 'V2', 'body' => 'B2']],
    ]);

    expect($this->resolver->contentFor('invoice.paid', 'push', tenantId: null)['title'])
        ->toBe('V1');

    $this->resolver->flushCache();

    expect($this->resolver->contentFor('invoice.paid', 'push', tenantId: null)['title'])
        ->toBe('V2');
});

it('allowedChannelsFor returns the type ceiling in config mode', function (): void {
    config(['notifications-max.content_source' => 'config']);

    expect($this->resolver->allowedChannelsFor('invoice.paid', tenantId: null))
        ->toBe(['push', 'email']);
});

it('allowedChannelsFor honours the admin override in database mode', function (): void {
    config(['notifications-max.content_source' => 'database']);

    NotificationTypeOverride::query()->create([
        'tenant_id' => null,
        'type_key' => 'invoice.paid',
        'allowed_channels' => ['push'], // admin restricted to push only
    ]);

    expect($this->resolver->allowedChannelsFor('invoice.paid', tenantId: null))
        ->toBe(['push']);
});

it('allowedChannelsFor honours override channels beyond the type config defaults', function (): void {
    // New behaviour: type's `allowed_channels` is a suggested default,
    // not a hard ceiling. Admins can opt in to any channel registered at
    // the package level via the Notification Settings page — including
    // ones the type config didn't list. Only the channel registry
    // (`notifications-max.channels`) caps what's possible.
    config(['notifications-max.content_source' => 'database']);

    // Type defaults to push only — but the channel registry has both
    // push and email available.
    config(['notifications' => [
        'invoice.paid' => [
            'category' => 'billing',
            'title' => 'Invoice {invoice_id} paid',
            'body' => 'Thanks {customer_name}',
            'allowed_channels' => ['push'], // type-default suggestion
        ],
    ]]);
    app(NotificationTypeRegistry::class)->flush();

    // Admin opted IN to email even though the type config didn't include it.
    NotificationTypeOverride::query()->create([
        'tenant_id' => null,
        'type_key' => 'invoice.paid',
        'allowed_channels' => ['push', 'email'],
    ]);

    expect($this->resolver->allowedChannelsFor('invoice.paid', tenantId: null))
        ->toBe(['push', 'email']);
});

it('allowedChannelsFor drops override entries that are not in the channel registry', function (): void {
    // Stale-override protection: a channel removed from
    // `notifications-max.channels` (e.g. SMS was wound down) silently
    // drops out of any stored override. Intersection is with the
    // registry, NOT the type's `allowed_channels` — admins keep the
    // freedom to opt in to channels beyond the type defaults.
    config(['notifications-max.content_source' => 'database']);

    NotificationTypeOverride::query()->create([
        'tenant_id' => null,
        'type_key' => 'invoice.paid',
        'allowed_channels' => ['push', 'sms'], // sms isn't in the registry
    ]);

    expect($this->resolver->allowedChannelsFor('invoice.paid', tenantId: null))
        ->toBe(['push']);
});

it('allowedChannelsFor bypasses admin override for mandatory types', function (): void {
    config(['notifications-max.content_source' => 'database']);

    NotificationTypeOverride::query()->create([
        'tenant_id' => null,
        'type_key' => 'audit.locked',
        'allowed_channels' => [], // admin tried to silence — ignored
    ]);

    expect($this->resolver->allowedChannelsFor('audit.locked', tenantId: null))
        ->toBe(['push', 'email']); // type config wins
});

it('configValueFor returns the no-override resolution', function (): void {
    $type = app(NotificationTypeRegistry::class)->find('invoice.paid');

    expect($this->resolver->configValueFor($type, 'push', 'title'))
        ->toBe('Paid: {invoice_id}')
        ->and($this->resolver->configValueFor($type, 'email', 'subject'))
        ->toBe('Payment received for #{invoice_id}');
});

it('shouldUseDatabase memoises the config lookup per instance', function (): void {
    config(['notifications-max.content_source' => 'config']);

    expect($this->resolver->shouldUseDatabase())->toBeFalse();

    // Change the config behind the resolver's back — instance cache holds.
    config(['notifications-max.content_source' => 'database']);

    expect($this->resolver->shouldUseDatabase())->toBeFalse();

    $this->resolver->flushCache();

    expect($this->resolver->shouldUseDatabase())->toBeTrue();
});
