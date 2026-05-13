<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications;

use BackedEnum;
use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Devletes\NotificationsMax\Contracts\TenantResolver;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages\CreateBroadcastNotification;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages\EditBroadcastNotification;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages\ListBroadcastNotifications;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages\ViewBroadcastNotification;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\RelationManagers\AudienceRelationManager;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\NotificationsMaxPlugin;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Admin-facing resource for composing and dispatching custom broadcasts.
 *
 * The resource is registered on a panel when that panel's plugin invocation
 * includes `->broadcaster()`. Authorization flows through
 * {@see \Devletes\NotificationsMax\Policies\BroadcastNotificationPolicy},
 * gated on the Spatie permission configured in
 * `notifications-max.broadcaster.permission`.
 *
 * Audience composition is delegated to the bound
 * {@see BroadcastAudienceResolver} — this resource does not assume a
 * particular audience shape.
 */
class BroadcastNotificationResource extends Resource
{
    /**
     * Model class Filament uses to hydrate and persist broadcast rows.
     * Resolution is delegated to {@see static::getModel()} so the concrete
     * class is driven by `notifications-max.broadcaster.model` config. Host
     * apps subclass the shipped model (e.g. to implement an approval
     * contract) and point the config key at the subclass without having to
     * fork this resource. The property below stays set to the package
     * default as a belt-and-braces fallback for early-boot contexts where
     * config access is unavailable.
     */
    protected static ?string $model = BroadcastNotification::class;

    public static function getModel(): string
    {
        $configured = config('notifications-max.broadcaster.model');

        if (is_string($configured) && $configured !== '' && class_exists($configured)) {
            return $configured;
        }

        return parent::getModel();
    }

    /**
     * Disable Filament's automatic tenant scoping. The package's model lives
     * inside an installable package and cannot assume the host's Tenant model
     * class, so it does not ship a `tenant()` relationship — which is what
     * Filament's auto-scoping machinery expects. Scoping is instead done
     * explicitly in {@see getEloquentQuery()} via the `TenantResolver`
     * contract, so list views remain correctly filtered.
     */
    protected static bool $isScopedToTenant = false;

    /**
     * Default nav group. Overridden per-panel via
     * {@see NotificationsMaxPlugin::broadcasterNavigationGroup()}. Resolution
     * happens in {@see static::getNavigationGroup()} so the override is
     * applied at request time rather than baked into the class property.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Notifications';

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Broadcasts';

    protected static ?string $modelLabel = 'Broadcast';

    protected static ?string $pluralModelLabel = 'Broadcasts';

    public static function form(Schema $schema): Schema
    {
        /** @var BroadcastAudienceResolver $audience */
        $audience = app(BroadcastAudienceResolver::class);

        return $schema->components([
            Grid::make(4)
                ->columnSpanFull()
                ->schema([
                    // Main lane: 3/4 width. Stacks Message (with inline CTA)
                    // then the Audience picker below it.
                    Section::make('Message')
                        ->columnSpan(3)
                        ->schema([
                            TextInput::make('subject')
                                ->required()
                                ->maxLength(120)
                                ->helperText('Shown as the notification title. Keep it short — bell dropdowns truncate after ~60 characters.'),
                            Textarea::make('body')
                                ->required()
                                ->maxLength(500)
                                ->rows(4)
                                ->helperText('Plain text only — formatting is stripped when the notification renders.'),
                            Grid::make(2)
                                ->schema([
                                    TextInput::make('action_url')
                                        ->label('Call-to-action URL')
                                        ->url()
                                        ->maxLength(500)
                                        ->helperText('Optional. Absolute URL; recipients see a button linking here.'),
                                    TextInput::make('action_label')
                                        ->label('Button label')
                                        ->default('View')
                                        ->maxLength(30),
                                ]),
                        ]),

                    // Sidebar: 1/4 width. Delivery options — channels listed
                    // one per row, plus scheduled-at picker.
                    Section::make('Delivery')
                        ->columnSpan(1)
                        ->schema([
                            CheckboxList::make('channels')
                                ->label('Channels')
                                ->required()
                                ->options(fn (): array => static::allowedChannelOptions())
                                ->default(fn (): array => static::defaultChannelDefaults()),
                            DateTimePicker::make('scheduled_at')
                                ->label('Send at')
                                ->helperText('Leave blank to send immediately.')
                                ->seconds(false)
                                ->minDate(now()),
                        ]),

                    // Wrap: 3/4 width. Audience picker occupies main lane
                    // below the Message section; right-hand lane stays empty
                    // on this row.
                    Section::make('Audience')
                        ->columnSpan(3)
                        ->description('Who receives this broadcast. Recipients are computed at send time — matching users added after scheduling will still receive the broadcast.')
                        ->schema([
                            $audience->formComponent('audience'),
                        ]),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Inline labels (label and value on the same line) are applied at
        // the schema level so every entry in this infolist inherits them
        // — avoids sprinkling ->inlineLabel() across every TextEntry.
        //
        // Every entry sources its value via an explicit `state()` callback
        // that pulls from the resolved BroadcastNotification record. This is
        // deliberate: it makes the infolist *embed-safe*. Filament's
        // implicit name-based state lookup (`TextEntry::make('status')` with
        // no `state()`) walks the schema container chain to find a record,
        // and that walk can land on a *different* outer record when this
        // infolist is embedded inside another schema (e.g. a host app's
        // task-modal that has its own outer record). Field names like
        // `status` and `channels` collide with common host-app columns,
        // turning a silent wrong-data bug into a hard TypeError when
        // strict-typed format/color closures see the wrong shape. Explicit
        // `state()` injects `$record` via Filament's parameter resolution,
        // which respects the model bound on the parent component — so the
        // entry always reads from the broadcast itself, regardless of where
        // the infolist is rendered.
        return $schema
            ->inlineLabel()
            ->components([
                Grid::make(4)
                    ->columnSpanFull()
                    ->schema([
                        // Message + metadata in one section. Subject/body
                        // are the headline; CTA and provenance sit below.
                        Section::make('Message')
                            ->columnSpan(3)
                            ->schema([
                                TextEntry::make('subject')
                                    ->state(fn (?BroadcastNotification $record): ?string => $record?->subject),
                                TextEntry::make('body')
                                    ->state(fn (?BroadcastNotification $record): ?string => $record?->body),
                                TextEntry::make('action_url')
                                    ->label('Call-to-action URL')
                                    ->state(fn (?BroadcastNotification $record): ?string => $record?->action_url)
                                    ->url(fn (?BroadcastNotification $record): ?string => $record?->action_url)
                                    ->openUrlInNewTab()
                                    ->placeholder('—'),
                                TextEntry::make('action_label')
                                    ->label('Button label')
                                    ->state(fn (?BroadcastNotification $record): ?string => $record?->action_label)
                                    ->placeholder('—'),
                                TextEntry::make('creator.name')
                                    ->label('Created by')
                                    ->state(fn (?BroadcastNotification $record): ?string => $record?->creator?->name)
                                    ->placeholder('—'),
                                TextEntry::make('created_at')
                                    ->label('Created at')
                                    ->state(fn (?BroadcastNotification $record) => $record?->created_at)
                                    ->dateTime('M j, Y H:i'),
                            ]),

                        // Sidebar: delivery + lifecycle snapshot.
                        Section::make('Delivery')
                            ->columnSpan(1)
                            ->schema([
                                TextEntry::make('channels')
                                    ->label('Channels')
                                    ->badge()
                                    ->color('gray')
                                    ->state(fn (?BroadcastNotification $record): ?array => $record?->channels)
                                    ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->state(fn (?BroadcastNotification $record): ?string => $record?->status)
                                    ->formatStateUsing(fn (?string $state): string => $state ? static::statusLabel($state) : '—')
                                    ->color(fn (?string $state): string => $state ? static::statusColor($state) : 'gray'),
                                TextEntry::make('scheduled_at')
                                    ->label('Scheduled')
                                    ->state(fn (?BroadcastNotification $record) => $record?->scheduled_at)
                                    ->dateTime('M j, Y H:i')
                                    ->placeholder('Immediate'),
                                TextEntry::make('sent_at')
                                    ->label('Sent')
                                    ->state(fn (?BroadcastNotification $record) => $record?->sent_at)
                                    ->dateTime('M j, Y H:i')
                                    ->placeholder('—'),
                                TextEntry::make('recipients_count')
                                    ->label('Recipients')
                                    ->state(fn (?BroadcastNotification $record): ?int => $record?->recipients_count)
                                    ->numeric()
                                    ->placeholder('—'),
                            ]),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        // Host apps can swap the audience relation manager via config
        // (see `notifications-max.broadcaster.audience_relation_manager`)
        // to show extra per-user columns — department, job title, etc. —
        // without forking the resource.
        $class = config(
            'notifications-max.broadcaster.audience_relation_manager',
            AudienceRelationManager::class,
        );

        return [
            is_string($class) && class_exists($class) ? $class : AudienceRelationManager::class,
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('subject')
                    ->searchable()
                    ->wrap()
                    ->limit(60),
                TextColumn::make('audience_summary')
                    ->label('Audience')
                    ->state(fn (BroadcastNotification $record): string => app(BroadcastAudienceResolver::class)->summarize($record->audience ?? []))
                    ->wrap()
                    ->limit(60)
                    ->color('gray'),
                TextColumn::make('channels')
                    ->label('Channels')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->separator(','),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => static::statusLabel($state))
                    ->color(fn (string $state): string => static::statusColor($state)),
                TextColumn::make('recipients_count')
                    ->label('Recipients')
                    ->numeric()
                    ->placeholder('—'),
                TextColumn::make('scheduled_at')
                    ->label('Scheduled')
                    ->dateTime('M j, Y H:i')
                    ->since()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('sent_at')
                    ->label('Sent')
                    ->dateTime('M j, Y H:i')
                    ->since()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('sent')
                    ->label('Sent')
                    ->nullable()
                    ->trueLabel('Sent')
                    ->falseLabel('Pending / scheduled')
                    ->queries(
                        true: fn ($q) => $q->where('status', 'sent'),
                        false: fn ($q) => $q->where('status', '!=', 'sent'),
                        blank: fn ($q) => $q,
                    ),
            ])
            ->recordUrl(fn (BroadcastNotification $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getNavigationGroup(): ?string
    {
        // Plugin::get() can throw when the resource class is loaded outside
        // a panel context (e.g. during `make:` artisan commands). Guard and
        // fall back to the static default so tooling keeps working.
        try {
            $override = NotificationsMaxPlugin::get()->getBroadcasterNavigationGroup();
        } catch (\Throwable) {
            $override = null;
        }

        return $override ?? parent::getNavigationGroup();
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();

        $tenantId = app(TenantResolver::class)->currentId();

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query;
    }

    public static function getPages(): array
    {
        // View page is configurable so host apps can subclass and inject
        // domain-specific infolist sections (approval progress, delivery
        // analytics, audit trails, etc.) without forking the resource.
        $viewPage = config('notifications-max.broadcaster.view_page', ViewBroadcastNotification::class);

        if (! is_string($viewPage) || ! class_exists($viewPage)) {
            $viewPage = ViewBroadcastNotification::class;
        }

        return [
            'index' => ListBroadcastNotifications::route('/'),
            'create' => CreateBroadcastNotification::route('/create'),
            'view' => $viewPage::route('/{record}'),
            'edit' => EditBroadcastNotification::route('/{record}/edit'),
        ];
    }

    /**
     * Look up the human-readable label for a status string. Falls back to a
     * prettified version of the raw status key when it isn't in the config
     * registry — keeps the UI sane even if a host app transitions a row to
     * a status it forgot to register.
     */
    public static function statusLabel(string $status): string
    {
        $label = config("notifications-max.broadcaster.statuses.{$status}.label");

        return is_string($label) && $label !== ''
            ? $label
            : Str::headline($status);
    }

    /**
     * Filament badge color for a status string. Defaults to 'gray' when the
     * status isn't in the config registry.
     */
    public static function statusColor(string $status): string
    {
        $color = config("notifications-max.broadcaster.statuses.{$status}.color");

        return is_string($color) && $color !== '' ? $color : 'gray';
    }

    /**
     * Channel options for the create/edit form, filtered to the
     * `broadcast.admin_custom` type's `allowed_channels`.
     *
     * Labels resolve from the channel registry
     * (`notifications-max.channels.{channel}.label`) so host-added channels
     * (sms, slack, …) pick up their configured label without touching this
     * method. Falls back to a humanised version of the channel key when the
     * registry doesn't declare one.
     *
     * @return array<string, string>
     */
    public static function allowedChannelOptions(): array
    {
        $type = app(NotificationTypeRegistry::class)->find('broadcast.admin_custom');

        return collect($type->allowedChannels)
            ->mapWithKeys(function (string $c): array {
                $label = config("notifications-max.channels.{$c}.label");

                return [$c => is_string($label) && $label !== ''
                    ? $label
                    : Str::headline($c)];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function defaultChannelDefaults(): array
    {
        return app(NotificationTypeRegistry::class)->find('broadcast.admin_custom')->defaultChannels;
    }
}
