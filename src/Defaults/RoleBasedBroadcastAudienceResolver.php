<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Defaults;

use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

/**
 * Optional {@see BroadcastAudienceResolver} — picks recipients by Spatie role.
 *
 * Ships with the package but is **not** the default. Point
 * `notifications-max.broadcaster.audience_resolver` at this class when the
 * host app uses spatie/laravel-permission and prefers to target broadcasts
 * by role membership rather than picking users individually.
 *
 * Audience JSON shape:
 *   {"role_ids": [1, 2, 3]}
 *
 * Tenant scoping: `matchingUsersQuery()` filters the user table to the given
 * tenant_id when one is passed. Role rows themselves are global (not
 * tenant-scoped in Spatie's default schema).
 *
 * Apps with richer targeting (departments, jobs, locations, boolean rules,
 * etc.) ship their own {@see BroadcastAudienceResolver} — bind it in
 * AppServiceProvider::register().
 */
class RoleBasedBroadcastAudienceResolver implements BroadcastAudienceResolver
{
    public function formComponent(string $name): Component
    {
        return Section::make('Audience')
            ->description('Who receives this broadcast. Recipients are computed at send time — matching users added after scheduling will still receive the broadcast.')
            ->schema([
                Select::make($name . '.role_ids')
                    ->label('Recipients (roles)')
                    ->helperText('Broadcast will reach every user assigned to any of the selected roles.')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => Role::query()->pluck('name', 'id')->all())
                    ->required()
                    ->minItems(1),
            ]);
    }

    public function matchingUsersQuery(array $audience, ?int $tenantId): Builder
    {
        $roleIds = $this->extractRoleIds($audience);

        $userClass = config('auth.providers.users.model');

        /** @var Builder $query */
        $query = $userClass::query();

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        if ($roleIds === []) {
            // Empty audience should reach nobody — explicit no-match rather
            // than accidentally broadcasting to the whole tenant.
            return $query->whereRaw('0 = 1');
        }

        return $query->whereHas('roles', function (Builder $q) use ($roleIds): void {
            $q->whereIn(config('permission.table_names.roles', 'roles') . '.id', $roleIds);
        });
    }

    public function countMatching(array $audience, ?int $tenantId): int
    {
        return $this->matchingUsersQuery($audience, $tenantId)->count();
    }

    public function summarize(array $audience): string
    {
        $roleIds = $this->extractRoleIds($audience);

        if ($roleIds === []) {
            return 'No recipients';
        }

        $names = Role::query()
            ->whereIn('id', $roleIds)
            ->pluck('name')
            ->all();

        $count = count($names);

        if ($count === 0) {
            return 'No recipients';
        }

        if ($count <= 3) {
            return sprintf('%d role%s (%s)', $count, $count === 1 ? '' : 's', implode(', ', $names));
        }

        return sprintf('%d roles (%s, +%d more)', $count, implode(', ', array_slice($names, 0, 2)), $count - 2);
    }

    /**
     * @param  array<string, mixed>  $audience
     * @return array<int, int>
     */
    protected function extractRoleIds(array $audience): array
    {
        $raw = $audience['role_ids'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($raw, 'is_numeric'))));
    }
}
