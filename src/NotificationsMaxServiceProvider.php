<?php

namespace Devletes\NotificationsMax;

use Devletes\NotificationsMax\Contracts\ActionUrlBuilder;
use Devletes\NotificationsMax\Contracts\AdminRoleResolver;
use Devletes\NotificationsMax\Contracts\AudienceResolver;
use Devletes\NotificationsMax\Contracts\AuthorizedBroadcaster;
use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Devletes\NotificationsMax\Contracts\PreferenceResolver;
use Devletes\NotificationsMax\Contracts\TenantResolver;
use Devletes\NotificationsMax\Defaults\DefaultAuthorizedBroadcaster;
use Devletes\NotificationsMax\Defaults\EloquentPreferenceResolver;
use Devletes\NotificationsMax\Defaults\NullTenantResolver;
use Devletes\NotificationsMax\Defaults\PathActionUrlBuilder;
use Devletes\NotificationsMax\Defaults\RoleBasedBroadcastAudienceResolver;
use Devletes\NotificationsMax\Defaults\SpatieAdminRoleResolver;
use Devletes\NotificationsMax\Defaults\SubdomainActionUrlBuilder;
use Devletes\NotificationsMax\Defaults\UserRoleAudienceResolver;
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
            ]);
    }

    public function packageRegistered(): void
    {
        // Type registry is a singleton — it caches parsed NotificationType
        // value objects for the request lifetime.
        $this->app->singleton(NotificationTypeRegistry::class);

        // Contract → default-implementation bindings. Consumers replace any
        // of these in their own AppServiceProvider::register() without
        // touching the package.
        //
        // Host apps with Filament's subdomain-tenancy get SubdomainActionUrlBuilder
        // as a sensible default; single-tenant or path-tenancy apps rebind
        // to PathActionUrlBuilder (also shipped).
        $this->app->bind(ActionUrlBuilder::class, SubdomainActionUrlBuilder::class);
        $this->app->bind(AdminRoleResolver::class, SpatieAdminRoleResolver::class);
        $this->app->bind(AudienceResolver::class, UserRoleAudienceResolver::class);
        $this->app->bind(AuthorizedBroadcaster::class, DefaultAuthorizedBroadcaster::class);
        $this->app->bind(BroadcastAudienceResolver::class, RoleBasedBroadcastAudienceResolver::class);
        $this->app->bind(PreferenceResolver::class, EloquentPreferenceResolver::class);
        $this->app->bind(TenantResolver::class, NullTenantResolver::class);

        // Keep path-builder directly resolvable so SubdomainActionUrlBuilder
        // can delegate to it for fallback cases.
        $this->app->bind(PathActionUrlBuilder::class, PathActionUrlBuilder::class);
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
     * before the host app adds its own config entry. Uses the runtime
     * registration API so this layers cleanly with any config-defined types.
     *
     * Host apps can still override this entry by defining their own
     * `broadcast.admin_custom` in config — config-side definitions take
     * precedence over the package's runtime baseline because they're
     * loaded first by {@see NotificationTypeRegistry::all()} (runtime
     * entries only override what's not already there during warmup; that
     * ordering is intentional to let hosts customize text, icon, colour,
     * and channel defaults without touching the package).
     *
     * NOTE: the registry currently has runtime entries WIN over config;
     * if you want the opposite for this specific reserved key, check
     * `has()` before registering.
     */
    protected function registerBroadcastAdminCustomType(): void
    {
        $registry = $this->app->make(NotificationTypeRegistry::class);

        // Let host apps override the defaults via their own config entry.
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
            'color' => 'info',
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
