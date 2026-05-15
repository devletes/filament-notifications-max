<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Registry\NotificationType;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;

beforeEach(function (): void {
    $this->registry = new NotificationTypeRegistry;
});

it('loads types from the configured config key', function (): void {
    config(['notifications' => [
        'order.placed' => [
            'label' => 'Order placed',
            'title' => 'Order #{order_id}',
            'body' => 'Total: {total}',
        ],
    ]]);

    $type = $this->registry->find('order.placed');

    expect($type)
        ->toBeInstanceOf(NotificationType::class)
        ->and($type->key)->toBe('order.placed')
        ->and($type->label)->toBe('Order placed')
        ->and($type->title)->toBe('Order #{order_id}');
});

it('throws when finding an unregistered key', function (): void {
    expect(fn () => $this->registry->find('not.a.real.type'))
        ->toThrow(RuntimeException::class, 'is not registered');
});

it('answers has() correctly for known and unknown keys', function (): void {
    expect($this->registry->has('test.simple'))->toBeTrue()
        ->and($this->registry->has('not.a.real.type'))->toBeFalse();
});

it('lets runtime registrations override config-defined entries', function (): void {
    config(['notifications' => [
        'overridable' => ['label' => 'From config'],
    ]]);

    $this->registry->register('overridable', ['label' => 'From runtime']);

    expect($this->registry->find('overridable')->label)->toBe('From runtime');
});

it('register() queues entries before warmup and merges them at first read', function (): void {
    // Register BEFORE all() has been called (pending path).
    $this->registry->register('queued.before.warmup', ['label' => 'Queued']);

    expect($this->registry->find('queued.before.warmup')->label)->toBe('Queued');
});

it('register() merges entries after warmup too', function (): void {
    // Warm cache via has() then register — covers the post-warmup branch.
    $this->registry->has('test.simple');

    $this->registry->register('post.warmup', ['label' => 'Post']);

    expect($this->registry->find('post.warmup')->label)->toBe('Post');
});

it('groups types by category', function (): void {
    config(['notifications' => [
        'a.one' => ['category' => 'alpha'],
        'a.two' => ['category' => 'alpha'],
        'b.one' => ['category' => 'beta'],
    ]]);

    expect($this->registry->byCategory('alpha'))->toHaveCount(2)
        ->and($this->registry->byCategory('beta'))->toHaveCount(1)
        ->and($this->registry->byCategory('missing'))->toHaveCount(0);
});

it('lists mandatory type keys', function (): void {
    config(['notifications' => [
        'mand.one' => ['mandatory' => true],
        'mand.two' => ['mandatory' => true],
        'opt.one' => ['mandatory' => false],
        'opt.two' => [],
    ]]);

    expect($this->registry->mandatoryKeys())
        ->toBe(['mand.one', 'mand.two']);
});

it('accepts a nested types config key when both flat and nested are present', function (): void {
    config(['notifications' => [
        'flat.one' => ['label' => 'Flat one'],
        'types' => [
            'nested.one' => ['label' => 'Nested one'],
        ],
    ]]);

    expect($this->registry->has('nested.one'))->toBeTrue()
        ->and($this->registry->has('flat.one'))->toBeFalse();
});

it('flush() clears both the cache and pending registrations', function (): void {
    $this->registry->register('will.be.gone', ['label' => 'Doomed']);
    $this->registry->has('test.simple'); // warm
    $this->registry->register('also.gone', ['label' => 'Doomed too']);

    $this->registry->flush();

    expect($this->registry->has('will.be.gone'))->toBeFalse()
        ->and($this->registry->has('also.gone'))->toBeFalse()
        // Config-defined types come back after flush.
        ->and($this->registry->has('test.simple'))->toBeTrue();
});
