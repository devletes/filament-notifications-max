<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications;

use BackedEnum;
use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Devletes\NotificationsMax\Contracts\TenantResolver;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages\CreateBroadcastNotification;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages\EditBroadcastNotification;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages\ListBroadcastNotifications;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\NotificationsMaxPlugin;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
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
    protected static ?string $model = BroadcastNotification::class;

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
                    ->state(fn (BroadcastNotification $r): string => app(BroadcastAudienceResolver::class)->summarize($r->audience ?? []))
                    ->wrap()
                    ->limit(60)
                    ->color('gray'),
                TextColumn::make('channels')
                    ->label('Channels')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->separator(','),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(function (BroadcastNotification $r): string {
                        if ($r->sent_at) {
                            return 'sent';
                        }

                        if ($r->scheduled_at) {
                            return 'scheduled';
                        }

                        return 'draft';
                    })
                    ->colors([
                        'success' => 'sent',
                        'warning' => 'scheduled',
                        'gray' => 'draft',
                    ]),
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
                        true: fn ($q) => $q->whereNotNull('sent_at'),
                        false: fn ($q) => $q->whereNull('sent_at'),
                        blank: fn ($q) => $q,
                    ),
            ])
            ->recordUrl(fn (BroadcastNotification $r): ?string => $r->sent_at ? null : static::getUrl('edit', ['record' => $r]));
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
        return [
            'index' => ListBroadcastNotifications::route('/'),
            'create' => CreateBroadcastNotification::route('/create'),
            'edit' => EditBroadcastNotification::route('/{record}/edit'),
        ];
    }

    /**
     * Channel options for the create/edit form, filtered to the
     * `broadcast.admin_custom` type's `allowed_channels`.
     *
     * @return array<string, string>
     */
    public static function allowedChannelOptions(): array
    {
        $type = app(NotificationTypeRegistry::class)->find('broadcast.admin_custom');

        return collect($type->allowedChannels)
            ->mapWithKeys(fn (string $c): array => [$c => match ($c) {
                'push' => 'Push',
                'email' => 'Email',
                default => Str::headline($c),
            }])
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
