<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Pages;

use Devletes\NotificationsMax\Registry\NotificationType;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Full-page notification center for the authenticated user.
 *
 * The bell dropdown caps at ~10–50 items and auto-paginates clumsily for users
 * with heavy notification volume. This page renders the full backlog as a
 * Filament table, with status / category filters and bulk mark-as-read /
 * delete actions.
 *
 * Scope: per-user. We query the user's notifications relation directly,
 * so there is no risk of cross-user leakage even without explicit tenant
 * scoping (the `tenant_id` column, if present, is incidental — `notifiable_id`
 * already implies tenant via the user relationship).
 */
class NotificationCenter extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Notifications';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $navigationLabel = 'All notifications';

    protected static ?string $title = 'Notifications';

    protected static ?string $slug = 'notifications';

    protected string $view = 'filament-notifications-max::pages.notification-center';

    /**
     * Sidebar nav visibility is config-driven so consumers can hide the
     * permanent nav entry when they prefer to surface this page solely via
     * the bell-panel "View all" link or a custom navigation pattern. The
     * URL route remains registered either way.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('notifications-max.notification_center.show_in_navigation', false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('data.title')
                    ->label('Title')
                    ->wrap()
                    ->weight(fn (DatabaseNotification $row): string => $row->unread() ? 'bold' : 'normal')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        // JSON path search: case-insensitive substring match against the
                        // serialized title field. SQLite/MySQL both support `JSON_EXTRACT`.
                        return $query->where('data->title', 'like', "%{$search}%");
                    }),
                TextColumn::make('data.body')
                    ->label('Message')
                    ->wrap()
                    ->limit(120)
                    ->color('gray'),
                TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->state(fn (DatabaseNotification $row): string => $this->categoryFor($row))
                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                TextColumn::make('created_at')
                    ->label('Received')
                    ->since()
                    ->sortable()
                    ->tooltip(fn (DatabaseNotification $row): string => $row->created_at->format('Y-m-d H:i')),
                TextColumn::make('read_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (DatabaseNotification $row): string => $row->unread() ? 'Unread' : 'Read')
                    ->color(fn (string $state): string => $state === 'Unread' ? 'primary' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'unread' => 'Unread',
                        'read' => 'Read',
                    ])
                    ->placeholder('All')
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'unread' => $query->whereNull('read_at'),
                            'read' => $query->whereNotNull('read_at'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('category')
                    ->label('Category')
                    ->options(fn (): array => $this->categoryOptions())
                    ->placeholder('All')
                    ->query(function (Builder $query, array $data): Builder {
                        $category = $data['value'] ?? null;

                        if (! $category) {
                            return $query;
                        }

                        $keys = $this->typeKeysForCategory($category);

                        if ($keys === []) {
                            // Defensive: no types in this category — return an empty result
                            // rather than letting an unconstrained query through.
                            return $query->whereRaw('0 = 1');
                        }

                        return $query->where(function (Builder $q) use ($keys): void {
                            foreach ($keys as $key) {
                                $q->orWhere('data->_meta->type_key', $key);
                            }
                        });
                    }),
            ])
            ->recordActions([
                Action::make('markAsRead')
                    ->label('Mark as read')
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->visible(fn (DatabaseNotification $row): bool => $row->unread())
                    ->action(fn (DatabaseNotification $row) => $row->markAsRead()),
                Action::make('markAsUnread')
                    ->label('Mark as unread')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (DatabaseNotification $row): bool => ! $row->unread())
                    ->action(fn (DatabaseNotification $row) => $row->markAsUnread()),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('markAsRead')
                        ->label('Mark as read')
                        ->icon('heroicon-o-check')
                        ->color('gray')
                        ->action(fn (Collection $records) => $records->each->markAsRead())
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('markAsUnread')
                        ->label('Mark as unread')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('gray')
                        ->action(fn (Collection $records) => $records->each->markAsUnread())
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ])
            ->emptyStateHeading('No notifications')
            ->emptyStateDescription('Your notifications will appear here as they arrive.')
            ->emptyStateIcon('heroicon-o-bell-slash');
    }

    protected function getTableQuery(): Builder
    {
        $user = Filament::auth()->user();

        if (! $user) {
            // Defensive: render an empty table rather than throwing when the
            // page renders outside an authenticated context.
            return DatabaseNotification::query()->whereRaw('0 = 1');
        }

        // `$user->notifications()` returns Builder for the morph relation
        // restricted to this notifiable — exactly the scoping we want.
        // Filter to filament-format rows only; other Laravel notifications
        // (system mails, broadcasts from other packages) shouldn't appear.
        return $user->notifications()
            ->getQuery()
            ->where('data->format', 'filament');
    }

    protected function categoryFor(DatabaseNotification $row): string
    {
        $typeKey = data_get($row->data, '_meta.type_key');

        if (! is_string($typeKey)) {
            return 'general';
        }

        try {
            return app(NotificationTypeRegistry::class)->find($typeKey)->category;
        } catch (\Throwable) {
            // Type might have been removed from the registry but old rows
            // still reference its key — degrade gracefully.
            return $this->fallbackCategoryFromKey($typeKey);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function categoryOptions(): array
    {
        $registry = app(NotificationTypeRegistry::class);

        return collect($registry->all())
            ->groupBy(fn (NotificationType $t) => $t->category)
            ->keys()
            ->mapWithKeys(fn (string $category): array => [$category => Str::headline($category)])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function typeKeysForCategory(string $category): array
    {
        return app(NotificationTypeRegistry::class)
            ->byCategory($category)
            ->map(fn (NotificationType $t) => $t->key)
            ->all();
    }

    /**
     * Fallback when a type key is no longer in the registry. Treat the first
     * dot-segment as a pseudo-category so old rows remain navigable.
     */
    protected function fallbackCategoryFromKey(string $typeKey): string
    {
        $segments = explode('.', $typeKey, 2);

        return $segments[0] ?: 'general';
    }
}
