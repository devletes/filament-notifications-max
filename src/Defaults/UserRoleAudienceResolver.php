<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Defaults;

use Devletes\NotificationsMax\Contracts\AudienceResolver;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal audience resolver shipped as the package default.
 *
 * Accepts an `$audience` shape of:
 *   ['user_ids' => [1, 2, 3], 'role_names' => ['hr_manager', 'admin']]
 *
 * Resolves to the union of: users with matching IDs + users with any of the
 * named Spatie roles. Host apps with richer audience models (departments,
 * jobs, segments, etc.) bind their own implementation instead.
 */
class UserRoleAudienceResolver implements AudienceResolver
{
    public function resolveToUsers(mixed $audience, ?int $tenantId): Collection
    {
        $audience = $this->normalize($audience);

        $userClass = config('auth.providers.users.model');

        if (! $userClass || ! class_exists($userClass)) {
            return new Collection;
        }

        $query = $userClass::query();

        if ($tenantId !== null && $this->modelHasTenantIdColumn($userClass)) {
            $query->where('tenant_id', $tenantId);
        }

        $query->where(function ($q) use ($audience, $userClass) {
            $matched = false;

            if (! empty($audience['user_ids'])) {
                $q->orWhereIn('id', $audience['user_ids']);
                $matched = true;
            }

            if (! empty($audience['role_names']) && method_exists($userClass, 'hasRole')) {
                $q->orWhereHas('roles', fn ($r) => $r->whereIn('name', $audience['role_names']));
                $matched = true;
            }

            // No criteria → match nothing (safer than matching everyone).
            if (! $matched) {
                $q->whereRaw('1 = 0');
            }
        });

        return $query->get();
    }

    public function count(mixed $audience, ?int $tenantId): int
    {
        // Delegating to a proper query builder count() would avoid loading
        // the full result set. For simplicity here, we materialize and count.
        return $this->resolveToUsers($audience, $tenantId)->count();
    }

    public function format(mixed $audience): string
    {
        $audience = $this->normalize($audience);

        $parts = [];

        if (! empty($audience['user_ids'])) {
            $parts[] = count($audience['user_ids']).' user(s)';
        }

        if (! empty($audience['role_names'])) {
            $parts[] = 'Roles: '.implode(', ', $audience['role_names']);
        }

        return empty($parts) ? 'No recipients' : implode(' | ', $parts);
    }

    public function formComponent(): Component
    {
        $userClass = config('auth.providers.users.model');

        return Section::make('Audience')
            ->description('Pick users directly and/or target everyone with specific roles.')
            ->schema([
                Select::make('audience.user_ids')
                    ->label('Specific users')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(function () use ($userClass) {
                        if (! $userClass || ! class_exists($userClass)) {
                            return [];
                        }

                        return $userClass::query()
                            ->limit(200)
                            ->get()
                            ->mapWithKeys(fn ($u) => [$u->getKey() => $u->name ?? $u->email ?? (string) $u->getKey()])
                            ->toArray();
                    }),

                Select::make('audience.role_names')
                    ->label('Roles')
                    ->multiple()
                    ->searchable()
                    ->options(function () {
                        if (! class_exists(\Spatie\Permission\Models\Role::class)) {
                            return [];
                        }

                        return \Spatie\Permission\Models\Role::query()
                            ->pluck('name', 'name')
                            ->toArray();
                    }),
            ]);
    }

    /**
     * @return array{user_ids: array<int>, role_names: array<string>}
     */
    protected function normalize(mixed $audience): array
    {
        if (is_array($audience)) {
            return [
                'user_ids' => array_values(array_filter($audience['user_ids'] ?? [], fn ($v) => $v !== null && $v !== '')),
                'role_names' => array_values(array_filter($audience['role_names'] ?? [], fn ($v) => $v !== null && $v !== '')),
            ];
        }

        return ['user_ids' => [], 'role_names' => []];
    }

    protected function modelHasTenantIdColumn(string $model): bool
    {
        try {
            $instance = new $model;

            return Schema::hasColumn($instance->getTable(), 'tenant_id');
        } catch (\Throwable) {
            return false;
        }
    }
}
