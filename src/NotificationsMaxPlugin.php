<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax;

use BackedEnum;
use Composer\InstalledVersions;
use Devletes\NotificationsMax\Filament\Pages\NotificationCenter;
use Devletes\NotificationsMax\Filament\Pages\NotificationPreferences;
use Devletes\NotificationsMax\Filament\Pages\NotificationSettings;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\BroadcastNotificationResource;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class NotificationsMaxPlugin implements Plugin
{
    protected bool $preferencesPage = false;

    protected bool $notificationCenterPage = false;

    protected bool $notificationSettingsPage = false;

    /**
     * Navigation icon for the notification settings page. Defaults to the
     * outlined-bell heroicon so the page is visually identifiable when
     * enabled. Set to `null` via {@see notificationSettingsIcon()} to
     * suppress the icon entirely, or pass a string / BackedEnum to use a
     * custom icon (e.g. `'heroicon-o-cog'` or `Heroicon::OutlinedCog`).
     */
    protected string|BackedEnum|null $notificationSettingsIcon = Heroicon::OutlinedBell;

    protected bool $broadcaster = false;

    protected string|UnitEnum|null $broadcasterNavigationGroup = null;

    protected bool $multiTenant = false;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public function getId(): string
    {
        return 'filament-notifications-max';
    }

    public function register(Panel $panel): void
    {
        // The bell + dropdown are core to this package — a panel with the
        // plugin registered always gets them. Disabling notifications while
        // installing a notifications plugin is counter-intuitive; the opt-out
        // toggle that used to live here was removed accordingly.
        $panel->databaseNotifications(condition: true);

        // User preferences page registers when ->preferencesPage() is set.
        // The page's `shouldRegisterNavigation()` returns false so it
        // doesn't appear in the sidebar; it's reached via the
        // "Preferences" header action on the NotificationCenter page.
        if ($this->preferencesPage) {
            $panel->pages([
                NotificationPreferences::class,
            ]);
        }

        if ($this->notificationCenterPage) {
            $panel->pages([
                NotificationCenter::class,
            ]);
        }

        if ($this->notificationSettingsPage) {
            // Admin-facing settings page — global content + channel
            // allowance per (tenant, type). Permission-gated via
            // `canAccess()` on the configured Spatie permission, so the
            // nav entry hides automatically for users without the
            // permission.
            $panel->pages([
                NotificationSettings::class,
            ]);
        }

        if ($this->broadcaster) {
            // Register the admin-broadcaster resource on this panel. Policy
            // gating means the resource only surfaces in the nav / permits
            // actions for users holding the configured Spatie permission
            // (default 'broadcast-notifications'). Consumers typically enable
            // this on an admin panel only.
            $panel->resources([
                BroadcastNotificationResource::class,
            ]);
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }

    // ---------------------------------------------------------------------
    // Feature toggles. Each returns $this for fluent chaining in the panel
    // provider: NotificationsMaxPlugin::make()->preferencesPage()->...
    // ---------------------------------------------------------------------

    /**
     * Enable the user-facing notification preferences (per-channel toggles
     * per type). The page itself sits behind the user-dropdown link rather
     * than the sidebar — call this on every panel where end users should
     * be able to manage their personal channel preferences.
     */
    public function preferencesPage(bool $condition = true): static
    {
        $this->preferencesPage = $condition;

        return $this;
    }

    public function notificationCenterPage(bool $condition = true): static
    {
        $this->notificationCenterPage = $condition;

        return $this;
    }

    /**
     * Enable the admin-facing notification settings page (per-tenant
     * channel allowance + content overrides). Permission-gated via
     * `notifications-max.notification_settings.permission`. Typically
     * enabled on the admin panel only.
     */
    public function notificationSettingsPage(bool $condition = true): static
    {
        $this->notificationSettingsPage = $condition;

        return $this;
    }

    /**
     * Override the navigation icon shown for the notification settings
     * page. Three usage shapes:
     *
     *   ->notificationSettingsIcon('heroicon-o-cog')         // string asset
     *   ->notificationSettingsIcon(Heroicon::OutlinedCog)    // BackedEnum
     *   ->notificationSettingsIcon(null)                     // no icon
     *
     * Without calling this method, the page renders with
     * {@see Heroicon::OutlinedBell} so the link is visually identifiable
     * in panels that don't otherwise standardise icon usage.
     */
    public function notificationSettingsIcon(string|BackedEnum|null $icon): static
    {
        $this->notificationSettingsIcon = $icon;

        return $this;
    }

    public function broadcaster(bool $condition = true): static
    {
        $this->broadcaster = $condition;

        return $this;
    }

    /**
     * Override the sidebar navigation group under which
     * {@see BroadcastNotificationResource} appears. Defaults to
     * "Notifications". Host apps pass an existing group label ("Content",
     * "Settings", etc.) or a UnitEnum case from their navigation group
     * enum to slot the resource into their own information architecture.
     */
    public function broadcasterNavigationGroup(string|UnitEnum|null $group): static
    {
        $this->broadcasterNavigationGroup = $group;

        return $this;
    }

    public function multiTenant(bool $condition = true): static
    {
        $this->multiTenant = $condition;

        return $this;
    }

    // ---------------------------------------------------------------------
    // Accessors used by the service provider and downstream features.
    // ---------------------------------------------------------------------

    public function hasPreferencesPage(): bool
    {
        return $this->preferencesPage;
    }

    public function hasNotificationCenterPage(): bool
    {
        return $this->notificationCenterPage;
    }

    public function hasNotificationSettingsPage(): bool
    {
        return $this->notificationSettingsPage;
    }

    public function getNotificationSettingsIcon(): string|BackedEnum|null
    {
        return $this->notificationSettingsIcon;
    }

    public function hasBroadcaster(): bool
    {
        return $this->broadcaster;
    }

    public function getBroadcasterNavigationGroup(): string|UnitEnum|null
    {
        return $this->broadcasterNavigationGroup;
    }

    /**
     * Installed version of the package, sourced from Composer's runtime
     * metadata. Returns the resolved version string when installed via
     * Composer (e.g. "0.2.0", "dev-main"), or "unknown" if the runtime
     * metadata isn't available — e.g. when the package is being executed
     * outside Composer's autoloader during testing.
     */
    public function getVersion(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'unknown';
        }

        try {
            $version = InstalledVersions::getPrettyVersion('devletes/filament-notifications-max');
        } catch (\OutOfBoundsException) {
            // Package isn't registered with the installed versions list —
            // typically only happens in early-boot edge cases.
            return 'unknown';
        }

        return $version ?? 'unknown';
    }

    public function isMultiTenant(): bool
    {
        return $this->multiTenant;
    }
}
