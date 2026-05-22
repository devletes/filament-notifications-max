<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Pages;

use BackedEnum;
use Devletes\NotificationsMax\Contracts\TenantResolver;
use Devletes\NotificationsMax\Filament\Pages\Concerns\BuildsNotificationPrefsLayout;
use Devletes\NotificationsMax\Models\UserNotificationPreference;
use Devletes\NotificationsMax\NotificationsMaxPlugin;
use Devletes\NotificationsMax\Registry\NotificationType;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/**
 * User-facing notification preferences page.
 *
 * Renders one tab per `category` in the registry, with one row per type and
 * a Toggle per allowed channel. Mandatory types render as disabled rows with
 * a "Required" label so users understand they can't be silenced.
 *
 * Channels users see for each type honour the admin's allowance (see
 * NotificationContentResolver::allowedChannelsFor) — when an admin disables
 * email for a type, users never see an email toggle for that type at all.
 *
 * State path: data[prefs][{slugified_type_key}][{channel}] = bool
 */
class NotificationPreferences extends Page implements HasForms
{
    use BuildsNotificationPrefsLayout;
    use InteractsWithForms;

    /**
     * Sidebar nav is suppressed by default — the page is always reachable
     * from the NotificationCenter "Preferences" header action regardless.
     * Hosts opt in to a permanent sidebar entry by calling
     * `NotificationsMaxPlugin::make()->preferencesPageInNavigation()` on
     * the relevant panel; the page reads that flag here.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::plugin()?->hasPreferencesPageInNavigation() ?? false;
    }

    /**
     * @return string|UnitEnum|null
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return static::plugin()?->getPreferencesPageNavigationGroup() ?? static::$navigationGroup;
    }

    public static function getNavigationLabel(): string
    {
        return static::plugin()?->getPreferencesPageNavigationLabel()
            ?? static::$navigationLabel
            ?? 'My notification preferences';
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return static::plugin()?->getPreferencesPageNavigationIcon();
    }

    public function getTitle(): string
    {
        return static::plugin()?->getPreferencesPageTitle()
            ?? static::$title
            ?? 'Notification preferences';
    }

    /**
     * Cheap shortcut to the plugin instance on the current panel. Returns
     * null when the page renders outside a panel context (tests booted
     * without Filament, console renders, etc.) — every caller already
     * threads through a default in that case.
     */
    protected static function plugin(): ?NotificationsMaxPlugin
    {
        try {
            return NotificationsMaxPlugin::get();
        } catch (Throwable) {
            return null;
        }
    }

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'My notification preferences';

    protected static ?string $title = 'Notification preferences';

    protected string $view = 'filament-notifications-max::pages.notification-preferences';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $registry = app(NotificationTypeRegistry::class);
        $resolver = app(NotificationContentResolver::class);
        $tenantId = app(TenantResolver::class)->currentId();
        $user = Filament::auth()->user();

        $prefs = [];

        foreach ($this->typesForUser($registry, $user) as $key => $type) {
            $safe = $this->safeKey($key);
            $explicit = $this->loadExplicit($user?->getKey(), $key);
            $channels = $resolver->allowedChannelsFor($key, $tenantId);

            foreach ($channels as $channel) {
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
        $user = Filament::auth()->user();
        $types = collect($this->typesForUser($registry, $user));

        $categories = $types
            ->groupBy(fn (NotificationType $t) => $t->category)
            ->sortKeys();

        // One Section per category, stacked top-to-bottom. Tabs were
        // replaced with sections after approval types were collapsed —
        // categories are short enough now that a single scrollable view
        // is friendlier than tab-switching, and users see everything at
        // once.
        $components = $categories->map(
            fn (Collection $categoryTypes, $category): Section => Section::make(Str::headline((string) $category))
                ->schema($this->buildCategoryComponents($categoryTypes))
                ->collapsible()
                ->collapsed(false),
        )->values()->all();

        return $schema
            ->components($components)
            ->statePath('data');
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
        $resolver = app(NotificationContentResolver::class);
        $tenantId = app(TenantResolver::class)->currentId() ?? $user->tenant_id ?? null;

        $submitted = $this->form->getState()['prefs'] ?? [];

        foreach ($this->typesForUser($registry, $user) as $key => $type) {
            // Mandatory types ignore saved state — don't persist rows for them.
            if ($type->mandatory) {
                continue;
            }

            $safe = $this->safeKey($key);
            $row = $submitted[$safe] ?? [];

            // Persist only the channels the user is actually allowed to
            // toggle. If admin re-enables a channel later, the user's
            // existing row reflects the last explicit choice; otherwise
            // the type's default kicks in.
            foreach ($resolver->allowedChannelsFor($key, $tenantId) as $channel) {
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

    /**
     * @return array<string, NotificationType>
     */
    protected function typesForUser(NotificationTypeRegistry $registry, ?Authenticatable $user): array
    {
        // Admin-controlled types (broadcasts, etc.) are sender-driven and
        // bypass user prefs entirely. Hiding them from this page keeps the
        // UI honest — users would otherwise see toggles that don't gate
        // anything.
        return array_filter(
            $registry->allForPanels($this->accessiblePanelIds($user)),
            fn (NotificationType $type): bool => ! $type->adminControlled,
        );
    }

    /**
     * @return array<int, string>
     */
    protected function accessiblePanelIds(?Authenticatable $user): array
    {
        try {
            $panels = Filament::getPanels();
        } catch (Throwable) {
            return [];
        }

        // No FilamentUser contract = Filament's default-allow.
        if ($user === null || ! $user instanceof FilamentUser) {
            return array_keys($panels);
        }

        return array_values(array_filter(
            array_map(
                fn (Panel $p): string => $p->getId(),
                $panels,
            ),
            fn (string $id) => $user->canAccessPanel($panels[$id]),
        ));
    }

    // ------------------------------------------------------------------
    // BuildsNotificationPrefsLayout abstracts
    // ------------------------------------------------------------------

    protected function statePathPrefix(): string
    {
        return 'prefs';
    }

    /**
     * @return array<int, string>
     */
    protected function channelsForType(NotificationType $type): array
    {
        $tenantId = app(TenantResolver::class)->currentId();

        return app(NotificationContentResolver::class)->allowedChannelsFor($type->key, $tenantId);
    }

    protected function isToggleDisabled(NotificationType $type): bool
    {
        return $type->mandatory;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

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
     * Breadcrumbs read as `navLabel > title`. Skips the navigation group
     * (e.g. "Settings") because users on this page already saw the
     * group in the sidebar before navigating — repeating it in the
     * trail is noise. Skips the title when it equals the nav label so
     * a host who didn't override both doesn't get a duplicated leaf.
     *
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        $breadcrumbs = [];

        $navLabel = static::getNavigationLabel();
        if ($navLabel !== '') {
            $breadcrumbs[] = $navLabel;
        }

        $title = $this->getTitle();
        if ($title !== '' && $title !== $navLabel) {
            $breadcrumbs[] = $title;
        }

        return $breadcrumbs;
    }
}
