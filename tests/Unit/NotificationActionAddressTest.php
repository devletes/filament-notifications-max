<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Support\NotificationActionAddress;

it('round-trips through toArray / fromArray', function (): void {
    $address = new NotificationActionAddress(
        resource: 'tasks',
        recordId: 17,
        panels: ['admin', 'employee'],
        preferredPanel: 'employee',
        tenantSlug: 'acme',
    );

    $rehydrated = NotificationActionAddress::fromArray($address->toArray());

    expect($rehydrated)->not->toBeNull()
        ->and($rehydrated->resource)->toBe('tasks')
        ->and($rehydrated->recordId)->toBe(17)
        ->and($rehydrated->panels)->toBe(['admin', 'employee'])
        ->and($rehydrated->preferredPanel)->toBe('employee')
        ->and($rehydrated->tenantSlug)->toBe('acme');
});

it('accepts a string record id', function (): void {
    $address = new NotificationActionAddress(
        resource: 'documents',
        recordId: 'doc-abc',
        panels: ['admin'],
    );

    expect($address->recordId)->toBe('doc-abc');
});

it('rejects an empty resource slug', function (): void {
    expect(fn () => new NotificationActionAddress(
        resource: '',
        recordId: 17,
        panels: ['admin'],
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects a zero / empty record id', function (): void {
    expect(fn () => new NotificationActionAddress(
        resource: 'tasks',
        recordId: 0,
        panels: ['admin'],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new NotificationActionAddress(
        resource: 'tasks',
        recordId: '',
        panels: ['admin'],
    ))->toThrow(InvalidArgumentException::class);
});

it('fromArray returns null on a null payload', function (): void {
    expect(NotificationActionAddress::fromArray(null))->toBeNull();
});

it('fromArray returns null when resource or record_id is missing', function (): void {
    expect(NotificationActionAddress::fromArray(['record_id' => 1, 'panels' => []]))->toBeNull()
        ->and(NotificationActionAddress::fromArray(['resource' => 'tasks', 'panels' => []]))->toBeNull();
});

it('fromArray cleans up the panels list', function (): void {
    $address = NotificationActionAddress::fromArray([
        'resource' => 'tasks',
        'record_id' => 17,
        // mixed whitespace, ints, nulls — should be reduced to clean strings.
        'panels' => [' admin ', '', null, 'employee', 42],
    ]);

    expect($address)->not->toBeNull()
        ->and($address->panels)->toBe(['admin', 'employee']);
});

it('fromArray treats empty / non-string preferred_panel as null', function (): void {
    $address = NotificationActionAddress::fromArray([
        'resource' => 'tasks',
        'record_id' => 17,
        'panels' => ['admin'],
        'preferred_panel' => '',
    ]);

    expect($address)->not->toBeNull()
        ->and($address->preferredPanel)->toBeNull();
});
