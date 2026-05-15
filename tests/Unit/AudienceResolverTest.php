<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Defaults\AudienceResolver;
use Devletes\NotificationsMax\Defaults\FilamentTenantResolver;
use Devletes\NotificationsMax\Tests\Stubs\User;

beforeEach(function (): void {
    $this->resolver = new AudienceResolver(new FilamentTenantResolver);
});

it('matchingUsersQuery returns users whose ids are in the audience payload', function (): void {
    $alice = User::query()->create(['email' => 'alice@x.test']);
    $bob = User::query()->create(['email' => 'bob@x.test']);
    User::query()->create(['email' => 'eve@x.test']); // not invited

    $users = $this->resolver
        ->matchingUsersQuery(['user_ids' => [$alice->id, $bob->id]], tenantId: null)
        ->get();

    expect($users->pluck('email')->all())->toBe(['alice@x.test', 'bob@x.test']);
});

it('matchingUsersQuery returns no rows when the audience is empty', function (): void {
    User::query()->create(['email' => 'alice@x.test']);

    $users = $this->resolver
        ->matchingUsersQuery(['user_ids' => []], tenantId: null)
        ->get();

    expect($users)->toHaveCount(0);
});

it('matchingUsersQuery returns no rows when the audience payload lacks a user_ids key', function (): void {
    User::query()->create(['email' => 'alice@x.test']);

    $users = $this->resolver
        ->matchingUsersQuery([], tenantId: null)
        ->get();

    expect($users)->toHaveCount(0);
});

it('matchingUsersQuery scopes to the given tenant id', function (): void {
    $alice = User::query()->create(['email' => 'alice@x.test', 'tenant_id' => 1]);
    $bob = User::query()->create(['email' => 'bob@x.test', 'tenant_id' => 2]);

    $users = $this->resolver
        ->matchingUsersQuery(['user_ids' => [$alice->id, $bob->id]], tenantId: 1)
        ->get();

    expect($users->pluck('email')->all())->toBe(['alice@x.test']);
});

it('countMatching returns the number of users the audience reaches', function (): void {
    $alice = User::query()->create(['email' => 'alice@x.test']);
    $bob = User::query()->create(['email' => 'bob@x.test']);

    expect($this->resolver->countMatching(
        ['user_ids' => [$alice->id, $bob->id]],
        tenantId: null,
    ))->toBe(2);
});

it('countMatching returns 0 for an empty audience', function (): void {
    expect($this->resolver->countMatching(['user_ids' => []], tenantId: null))->toBe(0);
});

it('summarize describes a small recipient list inline', function (): void {
    $alice = User::query()->create(['name' => 'Alice', 'email' => 'a@x.test']);
    $bob = User::query()->create(['name' => 'Bob', 'email' => 'b@x.test']);

    expect($this->resolver->summarize(['user_ids' => [$alice->id, $bob->id]]))
        ->toBe('2 users (Alice, Bob)');
});

it('summarize collapses a long recipient list with a +more suffix', function (): void {
    $ids = [];
    for ($i = 1; $i <= 5; $i++) {
        $ids[] = User::query()->create([
            'name' => "User {$i}",
            'email' => "u{$i}@x.test",
        ])->id;
    }

    expect($this->resolver->summarize(['user_ids' => $ids]))
        ->toBe('5 users (User 1, User 2, +3 more)');
});

it('summarize handles an empty audience gracefully', function (): void {
    expect($this->resolver->summarize([]))->toBe('No recipients')
        ->and($this->resolver->summarize(['user_ids' => []]))->toBe('No recipients');
});

it('summarize defends against bogus non-integer ids in the payload', function (): void {
    User::query()->create(['name' => 'Alice', 'email' => 'a@x.test']);

    expect($this->resolver->summarize(['user_ids' => ['not-numeric', null, 'also-bad']]))
        ->toBe('No recipients');
});
