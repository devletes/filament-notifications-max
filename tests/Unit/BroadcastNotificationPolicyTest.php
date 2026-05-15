<?php

declare(strict_types=1);

use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\Policies\BroadcastNotificationPolicy;
use Devletes\NotificationsMax\Tests\Stubs\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    $this->policy = new BroadcastNotificationPolicy;
    $this->user = User::query()->create(['email' => 'admin@x.test']);
});

/**
 * Grant a per-action permission to the test user via Gate::define so
 * `$user->can($permission)` returns true.
 */
function allowPermission(string $permission): void
{
    Gate::define($permission, fn () => true);
}

it('viewAny grants when ViewAny:BroadcastNotification is allowed', function (): void {
    allowPermission('ViewAny:BroadcastNotification');

    expect($this->policy->viewAny($this->user))->toBeTrue();
});

it('viewAny denies when ViewAny:BroadcastNotification is missing', function (): void {
    expect($this->policy->viewAny($this->user))->toBeFalse();
});

it('view, create, deleteAny each route to their own per-action permission', function (): void {
    allowPermission('View:BroadcastNotification');
    allowPermission('Create:BroadcastNotification');
    allowPermission('Delete:BroadcastNotification');

    $broadcast = new BroadcastNotification(['status' => 'draft']);

    expect($this->policy->view($this->user, $broadcast))->toBeTrue()
        ->and($this->policy->create($this->user))->toBeTrue()
        ->and($this->policy->deleteAny($this->user))->toBeTrue();
});

it('update refuses a sent broadcast regardless of permission', function (): void {
    allowPermission('Update:BroadcastNotification');

    $sent = new BroadcastNotification(['status' => 'sent']);

    expect($this->policy->update($this->user, $sent))->toBeFalse();
});

it('update grants when Update:BroadcastNotification is allowed and status is not sent', function (): void {
    allowPermission('Update:BroadcastNotification');

    $draft = new BroadcastNotification(['status' => 'draft']);

    expect($this->policy->update($this->user, $draft))->toBeTrue();
});

it('delete refuses broadcasts past the initial status', function (): void {
    allowPermission('Delete:BroadcastNotification');

    $queued = new BroadcastNotification(['status' => 'queued']);

    expect($this->policy->delete($this->user, $queued))->toBeFalse();
});

it('delete grants for initial-status broadcasts when permission is allowed', function (): void {
    allowPermission('Delete:BroadcastNotification');

    $draft = new BroadcastNotification(['status' => 'draft']);

    expect($this->policy->delete($this->user, $draft))->toBeTrue();
});

it('publish refuses non-publishable broadcasts even when Update is allowed', function (): void {
    allowPermission('Update:BroadcastNotification');

    config(['notifications-max.broadcaster.publishable_statuses' => ['draft']]);
    $sent = new BroadcastNotification(['status' => 'sent']);

    expect($this->policy->publish($this->user, $sent))->toBeFalse();
});

it('publish grants for publishable status with Update permission', function (): void {
    allowPermission('Update:BroadcastNotification');

    config(['notifications-max.broadcaster.publishable_statuses' => ['draft', 'approved']]);
    $draft = new BroadcastNotification(['status' => 'draft']);
    $approved = new BroadcastNotification(['status' => 'approved']);

    expect($this->policy->publish($this->user, $draft))->toBeTrue()
        ->and($this->policy->publish($this->user, $approved))->toBeTrue();
});

it('falls back to the legacy single-permission config when the per-action map is null', function (): void {
    config([
        'notifications-max.broadcaster.permissions' => null,
        'notifications-max.broadcaster.permission' => 'manage-broadcasts',
    ]);
    allowPermission('manage-broadcasts');

    $draft = new BroadcastNotification(['status' => 'draft']);

    expect($this->policy->viewAny($this->user))->toBeTrue()
        ->and($this->policy->create($this->user))->toBeTrue()
        ->and($this->policy->update($this->user, $draft))->toBeTrue();
});

it('denies all actions when neither permissions map nor single permission is configured', function (): void {
    config([
        'notifications-max.broadcaster.permissions' => null,
        'notifications-max.broadcaster.permission' => null,
    ]);

    expect($this->policy->viewAny($this->user))->toBeFalse()
        ->and($this->policy->create($this->user))->toBeFalse();
});
