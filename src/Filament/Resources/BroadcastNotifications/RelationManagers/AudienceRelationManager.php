<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\RelationManagers;

use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\BroadcastNotificationResource;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Virtual relation manager — the audience isn't a true Eloquent relationship
 * (it's computed from the broadcast's `audience` JSON column by whichever
 * {@see BroadcastAudienceResolver} is bound), so we override
 * {@see getRelationship()} to hand Filament's table a plain query builder
 * instead of a Relation. Filament accepts either; the result is a fully
 * paginated, searchable table of recipients on the broadcast's view page.
 *
 * Read-only by design. There is no "add recipient" flow — the audience is
 * declared by the audience payload and recomputed at dispatch time, so an
 * attach/detach UI would misrepresent how the system works.
 *
 * ## Extending the columns
 *
 * The shipped columns (avatar, name, email, read/unread badge) are the
 * least-common-denominator across Laravel user models. Host apps that want
 * to surface domain-specific info (department, job title, location, role
 * badges, …) extend this class and override whichever hook method they need:
 *
 *   - {@see getColumns()} — rebuild the whole list and reorder freely.
 *   - {@see getAdditionalColumns()} — insert extra columns between the
 *     email and read/unread columns, without having to re-declare the
 *     baseline four.
 *   - The per-column factory methods ({@see getAvatarColumn()},
 *     {@see getNameColumn()}, {@see getEmailColumn()},
 *     {@see getReadStatusColumn()}) can be overridden individually to
 *     tweak a single default — useful for swapping the `name` column's
 *     sort config to the host's real name columns.
 *
 * Wire the subclass via config:
 *
 *   // config/notifications-max.php
 *   'broadcaster' => [
 *       'audience_relation_manager' => App\Filament\Broadcaster\MyAudienceRelationManager::class,
 *   ],
 *
 * The broadcast resource reads that key when registering relation managers,
 * so no extra glue is required on the host side.
 */
class AudienceRelationManager extends RelationManager
{
    /**
     * A name is still required by Filament's relationship bookkeeping even
     * though our {@see getRelationship()} override sidesteps the actual
     * lookup. Kept short so it reads naturally in debug output.
     */
    protected static string $relationship = 'audience';

    protected static ?string $title = 'Audience';

    /**
     * Skip Filament's auto `viewAny`/`viewAll` authorization on the related
     * model (User). The parent broadcast page already gates access through
     * {@see \Devletes\NotificationsMax\Policies\BroadcastNotificationPolicy},
     * and the User model usually doesn't ship a dedicated viewAny policy —
     * letting Filament try to resolve one here would fail with either a
     * BadMethodCallException (no real `audience()` relationship on the
     * parent) or a missing-policy exception.
     */
    protected static bool $shouldSkipAuthorization = true;

    /**
     * Stubbed for Filament's bookkeeping; the actual table query is supplied
     * via {@see audienceQuery()} below through `->query()` on the table. We
     * can't use the standard `->relationship()` wiring here because
     * Filament's `getRelationshipQuery()` calls `->getQuery()` on what we
     * return — an Eloquent Builder's `getQuery()` returns Query\Builder, not
     * Eloquent\Builder, which clashes with Filament's return type.
     */
    public function getRelationship(): Relation | Builder
    {
        return $this->audienceQuery();
    }

    protected function audienceQuery(): Builder
    {
        /** @var BroadcastNotification $broadcast */
        $broadcast = $this->getOwnerRecord();

        $query = app(BroadcastAudienceResolver::class)
            ->matchingUsersQuery($broadcast->audience ?? [], $broadcast->tenant_id);

        // Enrich each user row with a `broadcast_read_at` subquery so the
        // read/unread column can render without N+1. Only meaningful once
        // the broadcast has actually fanned out (status = sent); for
        // draft / queued / scheduled rows there are no notification rows
        // in the table yet, so we skip the subquery and let every user
        // fall back to the "unread" default.
        if ($broadcast->isSent()) {
            $userClass = $this->userClass();
            $userTable = $this->userTable();

            $query->addSelect([
                // Filter by notifiable_type so the subquery doesn't match
                // a row belonging to a non-User notifiable that happens to
                // share an id. The qualified column name resolves the user
                // table from the configured model so host apps with a
                // non-standard table name still get a correct join.
                'broadcast_read_at' => DB::table('notifications')
                    ->select('read_at')
                    ->whereColumn('notifications.notifiable_id', $userTable.'.id')
                    ->where('notifications.notifiable_type', $userClass)
                    ->where('notifications.data->broadcast_id', $broadcast->getKey())
                    ->orderByDesc('notifications.created_at')
                    ->limit(1),
            ]);
        }

        return $query;
    }

    /**
     * FQCN of the host's User model. Resolved from
     * `config('auth.providers.users.model')` so the audience subquery and
     * search closures pick up the correct class without hardcoding.
     *
     * @return class-string<Model>
     */
    protected function userClass(): string
    {
        return (string) config('auth.providers.users.model');
    }

    /**
     * Database table backing the host's User model. Cached so we don't
     * instantiate a new model just to read `getTable()` on every render.
     */
    protected function userTable(): string
    {
        $class = $this->userClass();

        return (new $class)->getTable();
    }

    public function table(Table $table): Table
    {
        return $table
            // Bypass Filament's relationship pipeline entirely — ->query()
            // wins over ->relationship() inside Table::getQuery(), so the
            // resolver's Eloquent Builder flows straight into pagination /
            // search / sorting without the type mismatch that relationship
            // mode triggers for virtual (non-Eloquent-relation) data sources.
            ->query(fn (): Builder => $this->audienceQuery())
            ->columns($this->getColumns())
            ->defaultSort('email');
    }

    /**
     * The full ordered column list. The shipped default is intentionally
     * minimal (just name + email) — columns that depend on richer user
     * models (avatar, job title, roles, manager, …) or on broadcast
     * lifecycle (read/unread badge) are available as per-column factory
     * methods below, ready to be mixed in by a host subclass.
     *
     * Override to reorder freely, swap baseline columns for customised
     * versions, or inject additional columns.
     *
     * @return array<int, Column>
     */
    protected function getColumns(): array
    {
        return [
            $this->getNameColumn(),
            $this->getEmailColumn(),
        ];
    }

    /**
     * Circular avatar column. Uses Filament's canonical user-avatar resolver
     * which honours `HasAvatar::getFilamentAvatarUrl()` when the user model
     * implements it, and otherwise falls back to whatever default provider
     * the panel has configured (UI Avatars out of the box).
     */
    protected function getAvatarColumn(): Column
    {
        return ImageColumn::make('avatar')
            ->label('')
            ->circular()
            ->imageSize(32)
            ->state(fn (Model $record): string => Filament::getUserAvatarUrl($record))
            ->grow(false);
    }

    /**
     * Name column.
     *
     * Left non-sortable on purpose: the standard Laravel User model stores
     * `name` as a column (sortable trivially), but apps that split it into
     * `first_name` / `last_name` expose `name` as an accessor, which the
     * DB can't ORDER BY. Hosts that want name-sorted audiences override
     * this method and attach the right `sortable(...)` args.
     *
     * Searchable against whichever of `name` / `first_name` / `last_name`
     * / `email` actually exist on the `users` table — discovered at render
     * time via {@see Schema::hasColumn()} so both schema variants work out
     * of the box.
     */
    protected function getNameColumn(): Column
    {
        $userTable = $this->userTable();

        return TextColumn::make('name')
            ->label('Name')
            ->searchable(query: fn (Builder $query, string $search): Builder => $query
                ->where(fn (Builder $q) => $q
                    ->orWhere("{$userTable}.email", 'like', "%{$search}%")
                    ->when(Schema::hasColumn($userTable, 'name'), fn (Builder $q): Builder => $q->orWhere("{$userTable}.name", 'like', "%{$search}%"))
                    ->when(Schema::hasColumn($userTable, 'first_name'), fn (Builder $q): Builder => $q->orWhere("{$userTable}.first_name", 'like', "%{$search}%"))
                    ->when(Schema::hasColumn($userTable, 'last_name'), fn (Builder $q): Builder => $q->orWhere("{$userTable}.last_name", 'like', "%{$search}%"))
                )
            );
    }

    protected function getEmailColumn(): Column
    {
        return TextColumn::make('email')
            ->label('Email')
            ->searchable()
            ->sortable();
    }

    /**
     * Broadcast-aware status badge.
     *
     * Before the broadcast has fanned out (draft / queued / scheduled /
     * host-added workflow states such as pending_approval), every row
     * reflects the broadcast's own lifecycle — so an admin sees "Draft"
     * on a draft broadcast rather than a misleading "Unread" (which
     * would imply delivery has happened).
     *
     * Once status is `sent`, the badge flips to per-user read state
     * driven by the `broadcast_read_at` subquery added in
     * {@see audienceQuery()}: "Read" when the recipient's notification
     * row has a non-null `read_at`, otherwise "Unread".
     */
    protected function getReadStatusColumn(): Column
    {
        /** @var BroadcastNotification $broadcast */
        $broadcast = $this->getOwnerRecord();

        return TextColumn::make('broadcast_read_at')
            ->label('Status')
            ->badge()
            ->state(function (Model $record) use ($broadcast): string {
                if (! $broadcast->isSent()) {
                    return $broadcast->status;
                }

                return $record->broadcast_read_at ? 'read' : 'unread';
            })
            ->formatStateUsing(fn (string $state): string => match ($state) {
                'read' => 'Read',
                'unread' => 'Unread',
                default => BroadcastNotificationResource::statusLabel($state),
            })
            ->color(fn (string $state): string => match ($state) {
                'read' => 'success',
                'unread' => 'gray',
                default => BroadcastNotificationResource::statusColor($state),
            });
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
