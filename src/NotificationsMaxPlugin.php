<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax;

use Devletes\NotificationsMax\Filament\Pages\NotificationCenter;
use Devletes\NotificationsMax\Filament\Pages\NotificationPreferences;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\BroadcastNotificationResource;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

class NotificationsMaxPlugin implements Plugin
{
    protected bool $databaseNotifications = false;

    protected bool $preferencesPage = false;

    protected bool $notificationCenterPage = false;

    protected bool $broadcaster = false;

    protected ?string $broadcasterNavigationGroup = null;

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
        if ($this->databaseNotifications) {
            // Enable Filament's stock bell + dropdown. We don't override the
            // Livewire component anymore — toast/bell close behaviour follows
            // Filament defaults (delete on ×), which is safe for the toast
            // because GenericNotification::broadcastWith() gives the toast a
            // distinct id that no DB row matches (so toast X / auto-dismiss
            // are visual-only). Bell-panel × still deletes the matching row,
            // matching user expectation for an explicit dismiss gesture.
            $panel->databaseNotifications(condition: true);
        }

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
        // Runtime panel-level setup (e.g. render hooks for bell icon customizations)
        // will live here as features are implemented.
    }

    // ---------------------------------------------------------------------
    // Feature toggles. Each returns $this for fluent chaining in the panel
    // provider: NotificationsMaxPlugin::make()->databaseNotifications()->...
    // ---------------------------------------------------------------------

    public function databaseNotifications(bool $condition = true): static
    {
        $this->databaseNotifications = $condition;

        return $this;
    }

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

    public function broadcaster(bool $condition = true): static
    {
        $this->broadcaster = $condition;

        return $this;
    }

    /**
     * Override the sidebar navigation group under which
     * {@see BroadcastNotificationResource} appears. Defaults to
     * "Notifications". Host apps pass an existing group label ("Content",
     * "Settings", etc.) to slot the resource into their own information
     * architecture.
     */
    public function broadcasterNavigationGroup(?string $group): static
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

    public function hasDatabaseNotifications(): bool
    {
        return $this->databaseNotifications;
    }

    public function hasPreferencesPage(): bool
    {
        return $this->preferencesPage;
    }

    public function hasNotificationCenterPage(): bool
    {
        return $this->notificationCenterPage;
    }

    public function hasBroadcaster(): bool
    {
        return $this->broadcaster;
    }

    public function getBroadcasterNavigationGroup(): ?string
    {
        return $this->broadcasterNavigationGroup;
    }

    public function isMultiTenant(): bool
    {
        return $this->multiTenant;
    }
}
