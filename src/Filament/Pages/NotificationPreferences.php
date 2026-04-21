<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Pages;

use Devletes\NotificationsMax\Contracts\TenantResolver;
use Devletes\NotificationsMax\Models\UserNotificationPreference;
use Devletes\NotificationsMax\Registry\NotificationType;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * User-facing notification preferences page.
 *
 * Renders one tab per `category` in the registry, with one row per type and
 * a Toggle per allowed channel. Mandatory types render as disabled rows with
 * a "Required" label so users understand they can't be silenced.
 *
 * State path: data[prefs][{slugified_type_key}][{channel}] = bool
 */
class NotificationPreferences extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $title = 'Notification preferences';

    protected string $view = 'filament-notifications-max::pages.notification-preferences';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $registry = app(NotificationTypeRegistry::class);
        $user = Filament::auth()->user();

        $prefs = [];

        foreach ($registry->all() as $key => $type) {
            $safe = $this->safeKey($key);
            $explicit = $this->loadExplicit($user?->getKey(), $key);

            foreach ($type->allowedChannels as $channel) {
                $prefs[$safe][$channel] = $type->mandatory
                    ? true
                    : ($explicit[$channel] ?? $type->channelIsOnByDefault($channel));
            }
        }

        $this->form->fill(['prefs' => $prefs]);
    }

    public function form(Schema $schema): Schema
    {
        $registry = app(NotificationTypeRegistry::class);
        $types = collect($registry->all());

        $categories = $types
            ->groupBy(fn (NotificationType $t) => $t->category)
            ->sortKeys();

        return $schema
            ->components([
                Tabs::make('categories')
                    ->tabs(
                        $categories->map(function ($categoryTypes, $category) {
                            return Tab::make(Str::headline((string) $category))
                                ->schema(
                                    $categoryTypes
                                        ->map(fn (NotificationType $type) => $this->typeRow($type))
                                        ->all(),
                                );
                        })->values()->all(),
                    ),
            ])
            ->statePath('data');
    }

    protected function typeRow(NotificationType $type): Fieldset
    {
        $safe = $this->safeKey($type->key);

        $toggles = array_map(
            function (string $channel) use ($type, $safe) {
                return Toggle::make("prefs.{$safe}.{$channel}")
                    ->label($this->channelLabel($channel))
                    ->inline()
                    ->disabled($type->mandatory);
            },
            $type->allowedChannels,
        );

        $label = $type->mandatory
            ? $type->label.'  —  Required'
            : $type->label;

        return Fieldset::make($label)
            ->schema($toggles)
            ->extraAttributes(['data-type-key' => $type->key]);
    }

    public function save(): void
    {
        $user = Filament::auth()->user();

        if (! $user) {
            Notification::make()
                ->title('Could not save')
                ->body('Log in and try again.')
                ->danger()
                ->send();

            return;
        }

        $registry = app(NotificationTypeRegistry::class);
        $tenantId = app(TenantResolver::class)->currentId() ?? $user->tenant_id ?? null;

        $submitted = $this->form->getState()['prefs'] ?? [];

        foreach ($registry->all() as $key => $type) {
            // Mandatory types ignore saved state — don't persist rows for them.
            if ($type->mandatory) {
                continue;
            }

            $safe = $this->safeKey($key);
            $row = $submitted[$safe] ?? [];

            foreach ($type->allowedChannels as $channel) {
                UserNotificationPreference::set(
                    userId: $user->getKey(),
                    typeKey: $key,
                    channel: $channel,
                    enabled: (bool) ($row[$channel] ?? false),
                    tenantId: $tenantId,
                );
            }
        }

        Notification::make()
            ->title('Notification preferences saved')
            ->success()
            ->send();
    }

    protected function loadExplicit(int|string|null $userId, string $typeKey): array
    {
        if ($userId === null) {
            return [];
        }

        return UserNotificationPreference::query()
            ->where('user_id', $userId)
            ->where('notification_type_key', $typeKey)
            ->pluck('enabled', 'channel')
            ->map(fn ($v) => (bool) $v)
            ->all();
    }

    /**
     * Turn a dotted type key like "approval.request.action_needed" into a
     * form-safe slug like "approval_request_action_needed". Form state paths
     * use dots as separators, so keeping type keys out of the path avoids
     * nesting surprises.
     */
    protected function safeKey(string $key): string
    {
        return str_replace('.', '_', $key);
    }

    protected function channelLabel(string $channel): string
    {
        return match ($channel) {
            'push' => 'Push',
            'email' => 'Email',
            default => Str::headline($channel),
        };
    }
}
