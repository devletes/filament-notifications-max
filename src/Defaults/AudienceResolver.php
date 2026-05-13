<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Defaults;

use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Devletes\NotificationsMax\Contracts\TenantResolver;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Default {@see BroadcastAudienceResolver} — picks recipients by explicit
 * user selection from the app's configured user model.
 *
 * Audience JSON shape:
 *   {"user_ids": [1, 2, 3]}
 *
 * Tenant scoping: both `matchingUsersQuery()` and the form's search query
 * filter the user table by `tenant_id` when one is available. The form
 * reads the current tenant from the bound {@see TenantResolver}; matching
 * uses the `$tenantId` argument passed in by the caller (the dispatch job).
 *
 * Apps with richer targeting (roles, departments, locations, boolean rules,
 * etc.) ship their own {@see BroadcastAudienceResolver} — bind it in
 * `config/notifications-max.php` under `broadcaster.audience_resolver`.
 */
class AudienceResolver implements BroadcastAudienceResolver
{
    public function __construct(protected TenantResolver $tenantResolver) {}

    public function formComponent(string $name): Component
    {
        return Select::make($name . '.user_ids')
            ->label('Recipients')
            ->helperText('Broadcast will reach every selected user.')
            ->multiple()
            ->searchable()
            ->getSearchResultsUsing(function (string $search): array {
                return $this->baseQuery($this->tenantResolver->currentId())
                    ->where(fn (Builder $q) => $this->applySearch($q, $search))
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn (Model $u): array => [$u->getKey() => $this->labelFor($u)])
                    ->all();
            })
            ->getOptionLabelUsing(function ($value): ?string {
                $user = $this->userClass()::query()->find($value);

                return $user ? $this->labelFor($user) : null;
            })
            ->getOptionLabelsUsing(function (array $values): array {
                return $this->userClass()::query()
                    ->whereIn('id', $values)
                    ->get()
                    ->mapWithKeys(fn (Model $u): array => [$u->getKey() => $this->labelFor($u)])
                    ->all();
            })
            ->required()
            ->minItems(1);
    }

    public function matchingUsersQuery(array $audience, ?int $tenantId): Builder
    {
        $userIds = $this->extractUserIds($audience);

        $query = $this->baseQuery($tenantId);

        if ($userIds === []) {
            // Empty audience should reach nobody — explicit no-match rather
            // than accidentally broadcasting to the whole tenant.
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('id', $userIds);
    }

    public function countMatching(array $audience, ?int $tenantId): int
    {
        return $this->matchingUsersQuery($audience, $tenantId)->count();
    }

    public function summarize(array $audience): string
    {
        $userIds = $this->extractUserIds($audience);

        if ($userIds === []) {
            return 'No recipients';
        }

        $names = $this->userClass()::query()
            ->whereIn('id', $userIds)
            ->get()
            ->map(fn (Model $u): string => $this->nameFor($u))
            ->all();

        $count = count($names);

        if ($count === 0) {
            return 'No recipients';
        }

        if ($count <= 3) {
            return sprintf('%d user%s (%s)', $count, $count === 1 ? '' : 's', implode(', ', $names));
        }

        return sprintf('%d users (%s, +%d more)', $count, implode(', ', array_slice($names, 0, 2)), $count - 2);
    }

    protected function baseQuery(?int $tenantId): Builder
    {
        /** @var Builder $query */
        $query = $this->userClass()::query();

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query;
    }

    /**
     * Apply a search term across the conventional user columns. Defensive
     * about column presence so the resolver works on minimal User schemas
     * (no `first_name`/`last_name`) and richer ones alike — host apps with
     * unusual column layouts bind their own resolver.
     */
    protected function applySearch(Builder $query, string $search): Builder
    {
        $term = '%' . $search . '%';
        $model = $query->getModel();

        if ($this->hasAttribute($model, 'name')) {
            $query->orWhere('name', 'like', $term);
        }

        if ($this->hasAttribute($model, 'first_name')) {
            $query->orWhere('first_name', 'like', $term);
        }

        if ($this->hasAttribute($model, 'last_name')) {
            $query->orWhere('last_name', 'like', $term);
        }

        if ($this->hasAttribute($model, 'email')) {
            $query->orWhere('email', 'like', $term);
        }

        return $query;
    }

    /**
     * Schema-column lookups cached across keystrokes. The picker's
     * `searchable` callback fires on every input event, so re-checking
     * `Schema::hasColumn` per keystroke was the dominant cost on the
     * Audience field's autocomplete path. Static so the cache survives
     * resolver re-instantiation within the same process.
     *
     * @var array<string, bool>
     */
    protected static array $columnExistsCache = [];

    protected function hasAttribute(Model $model, string $column): bool
    {
        $table = $model->getTable();
        $cacheKey = "{$table}.{$column}";

        return static::$columnExistsCache[$cacheKey] ??= Schema::hasColumn($table, $column);
    }

    protected function labelFor(Model $user): string
    {
        $name = $this->nameFor($user);
        $email = $user->email ?? null;

        return is_string($email) && $email !== '' ? sprintf('%s (%s)', $name, $email) : $name;
    }

    protected function nameFor(Model $user): string
    {
        if (isset($user->name) && is_string($user->name) && $user->name !== '') {
            return $user->name;
        }

        $parts = array_filter([
            $user->first_name ?? null,
            $user->last_name ?? null,
        ], fn ($p): bool => is_string($p) && $p !== '');

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return 'User #' . $user->getKey();
    }

    /**
     * @return class-string<Model>
     */
    protected function userClass(): string
    {
        return (string) config('auth.providers.users.model');
    }

    /**
     * @param  array<string, mixed>  $audience
     * @return array<int, int>
     */
    protected function extractUserIds(array $audience): array
    {
        $raw = $audience['user_ids'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($raw, 'is_numeric'))));
    }
}
