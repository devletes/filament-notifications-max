<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Contracts;

use Filament\Schemas\Components\Component;
use Illuminate\Support\Collection;

/**
 * Turns audience criteria into a Collection of notifiable User models.
 *
 * Host apps bind their own implementation to integrate with whatever domain
 * model they use for audience selection (departments, roles, job titles,
 * segments, etc.). The package ships a minimal default that accepts explicit
 * user IDs + role names.
 *
 * The `$audience` parameter is intentionally typed as `mixed` so host apps
 * can pass whatever shape their UI produces — typically an array of rule
 * objects but it could also be a string identifier, a model, or a DSL blob.
 */
interface AudienceResolver
{
    /**
     * Resolve audience criteria to the users that should receive a broadcast.
     */
    public function resolveToUsers(mixed $audience, ?int $tenantId): Collection;

    /**
     * Count matching users without loading them. Used for live preview
     * ("this will notify 237 employees") before send confirmation.
     */
    public function count(mixed $audience, ?int $tenantId): int;

    /**
     * Human-readable one-line summary of the audience, used in list views and
     * confirmation modals (e.g. "Department: Engineering | Role: Manager").
     */
    public function format(mixed $audience): string;

    /**
     * The Filament form component used by the admin broadcaster to build
     * audience criteria. Host apps return their own rich picker; the default
     * returns a split user + role multi-select.
     */
    public function formComponent(): Component;
}
