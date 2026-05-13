<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax;

use Devletes\NotificationsMax\Contracts\ActionUrlBuilder;
use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Devletes\NotificationsMax\Contracts\BroadcastReleasePipeline;
use Devletes\NotificationsMax\Contracts\PreferenceResolver;
use Devletes\NotificationsMax\Contracts\TenantResolver;
use Devletes\NotificationsMax\Defaults\ImmediateBroadcastReleasePipeline;
use Devletes\NotificationsMax\Defaults\PathActionUrlBuilder;
use Devletes\NotificationsMax\Defaults\SubdomainActionUrlBuilder;
use Devletes\NotificationsMax\Listeners\FireDatabaseNotificationsSent;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\Observers\NotificationTenantObserver;
use Devletes\NotificationsMax\Policies\BroadcastNotificationPolicy;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class NotificationsMaxServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-notifications-max';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile('notifications-max')
            ->hasViews('filament-notifications-max')
            ->hasMigrations([
                'create_user_notification_preferences_table',
                'create_broadcast_notifications_table',
                'create_notification_type_overrides_table',
            ])
            ->hasCommand(\Devletes\NotificationsMax\Console\SeedContentCommand::class);
    }

    public function packageRegistered(): void
    {
        // Deep-merge the host's published config onto the package defaults.
        // Spatie's default `mergeConfigFrom` is a shallow merge — a host that
        // publishes the config file and only overrides `broadcaster.permission`
        // would otherwise lose the shipped `broadcaster.statuses`, etc.
        // array_replace_recursive lets the host override individual nested
        // keys without having to re-declare every package default.
        $packageDefaults = require __DIR__ . '/../config/notifications-max.php';
        $merged = array_replace_recursive($packageDefaults, config('notifications-max', []));
        config(['notifications-max' => $merged]);

        // Type registry is a singleton — it caches parsed NotificationType
        // value objects for the request lifetime.
        $this->app->singleton(NotificationTypeRegistry::class);

        // Contract → implementation wiring is config-driven (see
        // `notifications-max.resolvers` and `notifications-max.broadcaster`).
        // `bindIf` means any manual `app()->bind()` in a host
        // `AppServiceProvider::register()` wins over the config — useful
        // for tests and one-off container overrides.
        $this->bindFromConfig(TenantResolver::class, 'notifications-max.resolvers.tenant');
        $this->bindFromConfig(ActionUrlBuilder::class, 'notifications-max.resolvers.action_url');
        $this->bindFromConfig(PreferenceResolver::class, 'notifications-max.resolvers.preference');

        $this->bindFromConfig(BroadcastAudienceResolver::class, 'notifications-max.broadcaster.audience_resolver');
        $this->bindFromConfig(BroadcastReleasePipeline::class, 'notifications-max.broadcaster.release_pipeline');

        // Keep both URL builders directly resolvable so host apps that
        // compose them (e.g. subdomain builder delegating to path builder
        // for fallback) can fetch either without rebinding.
        $this->app->bind(PathActionUrlBuilder::class, PathActionUrlBuilder::class);
        $this->app->bind(SubdomainActionUrlBuilder::class, SubdomainActionUrlBuilder::class);
    }

    /**
     * Bind an abstract to the concrete class named at a config key. No-op
     * when the config key is missing or empty, or when the concrete class
     * doesn't exist — guards against typos and half-migrated configs.
     */
    protected function bindFromConfig(string $abstract, string $configKey): void
    {
        $concrete = config($configKey);

        if (! is_string($concrete) || $concrete === '' || ! class_exists($concrete)) {
            return;
        }

        $this->app->bindIf($abstract, $concrete);
    }

    public function packageBooted(): void
    {
        // Stamp `tenant_id` onto each notification row when running multi-tenant.
        // Mode is config-driven (`notifications-max.multi_tenant`):
        //   - true   → always register
        //   - false  → never register
        //   - 'auto' → only register if the `tenant_id` column exists (default)
        if ($this->shouldObserveTenant()) {
            DatabaseNotification::observe(NotificationTenantObserver::class);
        }

        // Bridge Laravel's notification pipeline to Filament's bell: fires
        // DatabaseNotificationsSent after every database-persisted
        // GenericNotification so the authenticated user's bell refreshes
        // in real time instead of waiting for its 30s polling interval.
        Event::listen(NotificationSent::class, FireDatabaseNotificationsSent::class);

        // Gate broadcast-notification actions on a configurable Spatie
        // permission (default 'broadcast-notifications'). Host apps can
        // override by binding their own policy for this model.
        \Illuminate\Support\Facades\Gate::policy(
            BroadcastNotification::class,
            BroadcastNotificationPolicy::class,
        );

        $this->registerBroadcastAdminCustomType();

        $this->registerHoverMarkAsRead();

        // Prepend our package's view path to the `filament-notifications`
        // namespace so our overridden `database-notifications.blade.php`
        // wins over Filament's stock copy. View::prependNamespace puts our
        // path FIRST in the resolver order — anything we don't override
        // continues to load from Filament's path as a fallback.
        View::prependNamespace(
            'filament-notifications',
            __DIR__ . '/../resources/views/vendor/filament-notifications',
        );
    }

    /**
     * Reserve the `broadcast.admin_custom` type key in the registry so the
     * dispatcher and preference resolver can route admin broadcasts even
     * before the host app adds its own config entry.
     *
     * The registry's general rule is runtime-registrations-win-over-config
     * (see {@see NotificationTypeRegistry::all()}). For THIS specific
     * reserved key we voluntarily yield to any config-defined entry via
     * the `has()` guard below — so host apps can override the package's
     * default label / icon / channels by adding their own
     * `broadcast.admin_custom` entry to `config/notifications.php`.
     */
    protected function registerBroadcastAdminCustomType(): void
    {
        $registry = $this->app->make(NotificationTypeRegistry::class);

        // Config-defined entry wins over our default — skip registration.
        if ($registry->has('broadcast.admin_custom')) {
            return;
        }

        $registry->register('broadcast.admin_custom', [
            'category' => 'announcements',
            'label' => 'Announcements from administrators',
            'description' => 'Custom messages sent by administrators to a group of users.',
            'title' => '{subject}',
            'body' => '{body}',
            'icon' => 'heroicon-o-megaphone',
            // Primary accent so admin announcements visually stand out from
            // the neutral-styled approval notifications.
            'color' => 'primary',
            'default_channels' => ['push'],
            'allowed_channels' => ['push', 'email'],
            'mandatory' => false,
            // action_url + action_label flow in via context on dispatch
            // when the admin set them on the broadcast; no static resource
            // binding because the destination is caller-specified.
        ]);
    }

    /**
     * Hover-to-mark-as-read bell-panel feature. Driven by the
     * `notifications-max.mark_read_on_hover` config:
     *   - null            → fully disabled (no asset shipped, no listeners)
     *   - integer (ms)    → enabled with the configured hover delay
     *
     * Delay is exposed to the JS via Filament's official `registerScriptData()`
     * channel (rendered as `window.filamentData.notificationsMax.hoverMarkAsReadDelay`).
     */
    protected function registerHoverMarkAsRead(): void
    {
        $delay = config('notifications-max.mark_read_on_hover');

        if (! is_int($delay) || $delay <= 0) {
            return;
        }

        FilamentAsset::registerScriptData([
            'notificationsMax' => [
                'hoverMarkAsReadDelay' => $delay,
            ],
        ], package: 'devletes/notifications-max');

        FilamentAsset::register(
            [
                Js::make(
                    'hover-mark-as-read',
                    __DIR__ . '/../resources/js/hover-mark-as-read.js',
                ),
            ],
            package: 'devletes/notifications-max',
        );
    }

    /**
     * Decide whether to register the multi-tenancy observer based on the
     * package's `multi_tenant` config (true | false | 'auto').
     *
     * 'auto' inspects the `notifications` table for a `tenant_id` column
     * — the schema lookup happens once at boot, not per notification.
     */
    protected function shouldObserveTenant(): bool
    {
        $mode = config('notifications-max.multi_tenant', 'auto');

        if ($mode === true) {
            return true;
        }

        if ($mode === false) {
            return false;
        }

        // 'auto' (or any other value) — probe the schema. Wrap in a try
        // so that boot doesn't fail when the DB isn't reachable yet
        // (e.g. during `php artisan package:discover` on a fresh install).
        try {
            return Schema::hasColumn('notifications', 'tenant_id');
        } catch (\Throwable) {
            return false;
        }
    }
}
