<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Contracts;

use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves who a custom admin broadcast should reach.
 *
 * Broadcasts store an arbitrary `audience` JSON column whose shape is entirely
 * up to the implementation. Role-based default impl stores `{"role_ids": [1,2]}`;
 * a host app with a richer targeting system (e.g. HRMS's `AppliesTo` DSL) can
 * bind its own implementation against this contract and store whatever shape
 * its matcher understands.
 *
 * Four responsibilities kept on one contract because they all pivot on the
 * same `audience` array and belong logically together:
 *
 *   - formComponent(): the form field the admin fills in to pick the audience
 *   - matchingUsersQuery(): an eloquent builder that resolves to the recipients
 *   - countMatching(): recipient count (used in the pre-send confirmation modal)
 *   - summarize(): human-readable string for list views and audit logs
 *
 * Implementations must respect tenant scoping — multi-tenant installs should
 * never return users from another tenant even if `audience` would otherwise
 * include them.
 */
interface BroadcastAudienceResolver
{
    /**
     * The Filament form component used to compose an audience. The component
     * must write its state into the given `$name` key on the form so the
     * saved payload ends up on the `audience` column of BroadcastNotification.
     */
    public function formComponent(string $name): Component;

    /**
     * Eloquent builder resolving to the set of users who should receive a
     * broadcast given the `$audience` payload. Must be tenant-scoped when
     * `$tenantId` is provided.
     *
     * @param  array<string, mixed>  $audience
     */
    public function matchingUsersQuery(array $audience, ?int $tenantId): Builder;

    /**
     * Count of users the given audience would reach — used in pre-send
     * confirmation modals. Implementations should prefer a cheap `count()`
     * over materializing the user collection.
     *
     * @param  array<string, mixed>  $audience
     */
    public function countMatching(array $audience, ?int $tenantId): int;

    /**
     * Render a short, human-readable summary of the audience for list
     * columns and audit entries. e.g. "2 roles (Admin, Manager)" or
     * "Department: Engineering · Role: Manager". Keep under ~80 chars.
     *
     * @param  array<string, mixed>  $audience
     */
    public function summarize(array $audience): string;
}
