<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Action Label
    |--------------------------------------------------------------------------
    |
    | Text shown on a notification's primary action button when the dispatch
    | call doesn't provide an explicit `action_label` in its context.
    |
    */

    'default_action_label' => 'View',

    /*
    |--------------------------------------------------------------------------
    | Type Registry Source
    |--------------------------------------------------------------------------
    |
    | Dot-notation config key that holds the host app's notification-type
    | definitions. Defaults to `notifications` (i.e. config/notifications.php).
    | Point to a different key (e.g. `hrms-events.types`) when the default
    | name collides with an existing config file in the host app.
    |
    | Types may also be registered programmatically at runtime via
    | NotificationTypeRegistry::register() / ::registerMany() — useful for
    | third-party packages that ship their own type catalog. Runtime
    | registrations win when keys collide.
    |
    */

    'types_config_key' => 'notifications',

    /*
    |--------------------------------------------------------------------------
    | Notification Type Defaults
    |--------------------------------------------------------------------------
    |
    | Fallback values applied to a registry entry when a given key is omitted.
    | Override per-type by setting the same key in the type definition.
    |
    */

    'type_defaults' => [
        'icon' => 'heroicon-o-bell',
        'target_panel' => 'admin',
        'category' => 'general',
        'default_channels' => ['push'],
        'allowed_channels' => ['push', 'email'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel Registry
    |--------------------------------------------------------------------------
    |
    | Logical channels users see in preferences ("push", "email", "sms",
    | "slack", …). Each entry is a self-describing definition the package
    | uses for THREE things:
    |
    |   1. Routing      — `physical` lists the Laravel notification channels
    |                     the dispatcher actually fires. "push" combines
    |                     database + broadcast so users either get both or
    |                     neither (a toast without a bell row would vanish
    |                     forever).
    |
    |   2. Content      — `content_fields` declares the fields admins fill
    |                     in when overriding content for this channel. Each
    |                     field's value is its editor type:
    |                       'string'           single-line text
    |                       'text'             multi-line text
    |                       'rich-text'        full WYSIWYG, HTML output
    |                                          (email — headings, tables,
    |                                          attachments)
    |                       'template-select'  dropdown of registered
    |                                          email templates (special-cased)
    |                     The Manage Content modal renders an editor per
    |                     field automatically — adding a new channel is a
    |                     config change, never a UI change.
    |
    |   3. Richness    — `richness` declares how the channel renders body-
    |                    like content downstream. Values:
    |                       'plain'     plain text, no interpretation
    |                       'html'      HTML, sanitized by the renderer
    |                                   (e.g. Filament bell/toast, mail)
    |                       'markdown'  channel-native markdown dialect
    |                                   (e.g. Slack mrkdwn)
    |                    The placeholder substituter ({@see GenericNotification::render()})
    |                    escapes interpolated context values per dialect so
    |                    untrusted values can't break out of the surrounding
    |                    template — templates themselves are trusted. Titles
    |                    and subjects are always treated as plain regardless
    |                    of channel richness. Defaults to 'plain' when
    |                    omitted, so adding a new channel without thinking
    |                    about richness fails safely.
    |
    |   4. Preferences  — `label` is what users / admins see in the
    |                     preferences toggles.
    |
    | Apps wanting their own channel (SMS, Slack, Teams, webhook, …) add
    | an entry here, route the physical channel name to a Laravel
    | notification channel class via standard Laravel `via()` mechanics,
    | and the package picks it up everywhere — preferences UI, content
    | overrides, channel allowance, dispatch — without touching plugin code.
    |
    */

    'channels' => [
        'push' => [
            'label' => 'Push',
            'physical' => ['database', 'broadcast'],
            // Plain — admins author plain text, same as every other
            // "push" surface in the world (mobile OS, desktop OS, Slack,
            // browser push). Filament's bell + toast happen to render
            // HTML, but the channel handler HTML-escapes on the way out
            // so the surface stays consistent. Don't change this to
            // 'html' to unlock rich content; the constraint is intentional.
            'richness' => 'plain',
            'content_fields' => [
                'title' => 'string',
                'body' => 'text',
            ],
        ],
        'email' => [
            'label' => 'Email',
            'physical' => ['mail'],
            'richness' => 'html',
            'content_fields' => [
                'subject' => 'string',
                'body' => 'rich-text',
                'template' => 'template-select',
            ],
        ],

        // Pre-built channels — uncomment to activate. Each requires the
        // corresponding third-party Laravel notification channel package
        // (see `channel_handlers` block below for which composer dep
        // each one needs).

        // 'sms' => [
        //     'label' => 'SMS',
        //     'physical' => ['twilio'],  // or ['vonage'] — pick one
        //     'content_fields' => ['body' => 'text'],
        // ],

        // 'slack' => [
        //     'label' => 'Slack',
        //     'physical' => ['slack'],
        //     // Slack's native flavour is mrkdwn — *bold*, _italic_,
        //     // ~strike~, `code`, <url|label>. Templates are trusted
        //     // mrkdwn; interpolated context values are backslash-escaped
        //     // by GenericNotification::render() so a user-supplied
        //     // `*foo*` doesn't accidentally trigger bold formatting.
        //     'richness' => 'markdown',
        //     'content_fields' => ['body' => 'markdown'],
        // ],

        // 'discord' => [
        //     'label' => 'Discord',
        //     'physical' => ['discord'],
        //     'content_fields' => ['body' => 'text'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel Handlers
    |--------------------------------------------------------------------------
    |
    | Maps a physical channel name (the thing Laravel's ChannelManager
    | resolves and the user's `via()` returns) to a class implementing
    | {@see \Devletes\NotificationsMax\Contracts\ChannelHandler}. The
    | handler renders the per-channel payload — array for database,
    | MailMessage for mail, TwilioSmsMessage for twilio, etc.
    |
    | The package ships handlers for every channel listed below. Built-
    | in channels (database / broadcast / mail) work out of the box.
    | Optional channels (twilio / vonage / slack / discord) require the
    | host to install the matching third-party Laravel notification
    | channel package — without it the handler's import resolves to a
    | missing class at runtime. Install only the ones you'll use:
    |
    |   composer require laravel-notification-channels/twilio    # for twilio
    |   composer require laravel/vonage-notification-channel     # for vonage
    |   composer require laravel/slack-notification-channel      # for slack
    |   composer require laravel-notification-channels/discord   # for discord
    |
    | Hosts customise a channel's rendering by pointing the map at a
    | subclass — e.g. extend `DatabaseChannelHandler` to add extra audit
    | fields, then set `'database' => YourDatabaseChannelHandler::class`.
    |
    | Hosts adding a wholly new channel (one this package doesn't ship a
    | handler for) subclass `GenericNotification`, add a `to{Channel}`
    | method, and set `default_notification_class` below to their subclass.
    |
    */

    'channel_handlers' => [
        'database'  => \Devletes\NotificationsMax\Channels\DatabaseChannelHandler::class,
        'broadcast' => \Devletes\NotificationsMax\Channels\BroadcastChannelHandler::class,
        'mail'      => \Devletes\NotificationsMax\Channels\MailChannelHandler::class,
        'twilio'    => \Devletes\NotificationsMax\Channels\TwilioChannelHandler::class,
        'vonage'    => \Devletes\NotificationsMax\Channels\VonageChannelHandler::class,
        'slack'     => \Devletes\NotificationsMax\Channels\SlackChannelHandler::class,
        'discord'   => \Devletes\NotificationsMax\Channels\DiscordChannelHandler::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Notification Class
    |--------------------------------------------------------------------------
    |
    | The notification class the dispatcher instantiates when a type entry
    | doesn't declare its own `notification_class`. Defaults to the
    | package's `GenericNotification`, which carries `to{Channel}` methods
    | for every channel listed under `channel_handlers` above.
    |
    | Hosts adding a custom channel (one this package doesn't ship a
    | handler for) subclass `GenericNotification`, add a `to{Channel}`
    | method, and point this key at their subclass:
    |
    |   'default_notification_class' => \App\Notifications\MyAppNotification::class,
    |
    | Per-type overrides (`notification_class` on a type registry entry)
    | still win when present.
    |
    */

    'default_notification_class' => \Devletes\NotificationsMax\Notifications\GenericNotification::class,

    /*
    |--------------------------------------------------------------------------
    | Email Templates
    |--------------------------------------------------------------------------
    |
    | Blade views the email channel can wrap an admin's body content in.
    | Each entry is `name => view`. `name` is what the admin picks in the
    | template dropdown; `view` is the Blade view that receives the rendered
    | body as `$content`, plus standard variables (`$subject`, `$action`,
    | `$recipient`).
    |
    | The package ships a single neutral default. Hosts add their own brand
    | wrappers by registering Blade views and listing them here.
    |
    */

    'email_templates' => [
        'default' => 'filament-notifications-max::mail.default',
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Source
    |--------------------------------------------------------------------------
    |
    | `'config'`   — Notification titles, bodies, and channel allowance come
    |                straight from the type config (host's
    |                `config/notifications.php`). Zero-config default; no
    |                database overrides considered.
    |
    | `'database'` — Per-(tenant, type) overrides are read from the
    |                `notification_type_overrides` table. Rows can be
    |                seeded from config defaults via
    |                `php artisan notifications-max:seed-content`.
    |                Resolution: row exists → DB values are authoritative
    |                (NULL on individual fields falls back to config);
    |                row missing → falls back to config.
    |
    | Switch modes per environment via .env if useful (NOTIFICATIONS_MAX_CONTENT_SOURCE).
    |
    */

    'content_source' => env('NOTIFICATIONS_MAX_CONTENT_SOURCE', 'config'),

    /*
    |--------------------------------------------------------------------------
    | Notification Settings Page (Admin)
    |--------------------------------------------------------------------------
    |
    | The admin-facing settings page is opted into per-panel via
    | `NotificationsMaxPlugin::make()->notificationSettingsPage()`. When
    | that fluent method is called, the page is mounted at
    | `/notification-settings` under whichever nav group it declares
    | (`Settings` by default).
    |
    | `permission` is the Spatie permission required to access the page.
    | Users without it get a 403; the nav entry is also hidden. Default
    | is `'View:NotificationSettings'` — that's the permission name
    | Filament Shield's auto-discovery generates for this page, so
    | running `php artisan shield:generate` (or any equivalent sync
    | command) creates the row and grants it to the configured
    | super_admin role for free. Hosts not using Shield can override
    | this to any string their authorization layer recognises, or set
    | to `null` to disable the permission gate entirely (the plugin's
    | per-panel `->notificationSettingsPage()` opt-in is then the only
    | access control).
    |
    */

    'notification_settings' => [
        'permission' => 'View:NotificationSettings',
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation Links
    |--------------------------------------------------------------------------
    |
    | URLs the package surfaces in admin UI for "learn more" links — most
    | notably the read-only callout on the notification settings page when
    | the package is in config mode. Hosts can repoint these at their own
    | internal runbooks / wikis if they prefer their team to stay
    | in-product.
    |
    */

    'docs' => [
        'database_mode_url' => 'https://filament.devletes.com/notifications-max#database-mode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Toast (Broadcast) Defaults
    |--------------------------------------------------------------------------
    |
    | Auto-dismiss duration for the broadcast toast in milliseconds. Use
    | the string 'persistent' to disable auto-dismiss. Per-type override:
    | set `'duration'` on a registry entry (numeric ms or 'persistent').
    |
    */

    'toast' => [
        'duration' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mark As Read On Hover
    |--------------------------------------------------------------------------
    |
    | When the user hovers over an unread notification in the bell-panel
    | dropdown for this many milliseconds, it is marked as read automatically.
    |
    | Set to `null` to disable the feature entirely (no JS shipped, no
    | listeners attached). Set to an integer to enable with that delay.
    |
    | Recommended: 2000 (snappy) – 3000 (deliberate). Below ~1500 risks
    | accidental triggers when scanning the dropdown.
    |
    */

    'mark_read_on_hover' => null,

    /*
    |--------------------------------------------------------------------------
    | Notification Center Page
    |--------------------------------------------------------------------------
    |
    | Settings for the full-page notification list rendered when a panel calls
    | `NotificationsMaxPlugin::make()->notificationCenterPage()`. The page is
    | always reachable by URL (and from the bell-panel "View all" link); this
    | section just controls whether it also appears as a sidebar nav item.
    |
    | `show_in_navigation` (default false): when true, the page appears as a
    | sidebar nav entry. Default is false because the bell-panel "View all"
    | link already provides a natural entry point — consumers who want a
    | permanent sidebar shortcut flip this to true.
    |
    */

    'notification_center' => [
        'show_in_navigation' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Category Badge Colors
    |--------------------------------------------------------------------------
    |
    | Filament badge color applied to the "Category" column on the notification
    | center table. Keyed by the `category` string declared on each registered
    | notification type (see the host app's `config/notifications.php`).
    |
    | Categories live with the type definitions because they are grouping
    | labels for filtering; colors live here because they are a presentational
    | concern separate from the types themselves. Any category not listed
    | falls back to the neutral 'gray' so the list stays visually quiet and
    | only the categories the host marks noteworthy draw the eye.
    |
    | Valid colors: any Filament color name ('gray', 'primary', 'success',
    | 'warning', 'danger', 'info') or a registered custom color.
    |
    */
    'category_colors' => [
        'announcements' => 'gray',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Status Badge Colors
    |--------------------------------------------------------------------------
    |
    | Filament badge color applied to the "Status" column on the notification
    | center table (read vs unread). Keyed by the lowercase status string.
    | Anything not listed falls back to 'gray'.
    |
    | Defaults make unread rows pop in the accent color so new notifications
    | are easy to spot; read rows fade to neutral.
    |
    */
    'status_colors' => [
        'unread' => 'gray',
        'read' => 'info',
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | When enabled, the package observes every notification insert and stamps
    | the notifiable's `tenant_id` onto the row — provided the `notifications`
    | table has a `tenant_id` column. Disable on single-tenant installs to
    | skip the observer entirely (one less event listener per insert).
    |
    | Modes:
    |   - true   → always register the observer (will no-op if the column
    |              isn't there, but still costs a schema lookup at boot)
    |   - false  → never register the observer
    |   - 'auto' → register only if the `tenant_id` column exists on the
    |              `notifications` table (decided once at boot)
    |
    */

    'multi_tenant' => 'auto',

    /*
    |--------------------------------------------------------------------------
    | Admin Broadcaster
    |--------------------------------------------------------------------------
    |
    | Settings for the admin-composed broadcast feature. Only active on panels
    | that register it via `NotificationsMaxPlugin::make()->broadcaster()`.
    |
    | permissions
    |   Per-action permission map. Each key corresponds to a policy method
    |   on BroadcastNotificationPolicy. The shipped defaults follow Filament
    |   Shield's auto-generated naming convention, so running
    |   `php artisan shield:generate` produces matching rows and the
    |   configured super_admin role syncs them automatically — no policy
    |   override required for Shield installs.
    |
    |   Hosts that don't use Shield can either rewrite this map to their
    |   own per-action permission names, or set it to `null` and use the
    |   legacy single-permission fallback below.
    |
    | permission
    |   Legacy single-permission fallback. Used only when `permissions`
    |   above is null / empty. Provided for installs that gate every
    |   broadcast action behind one permission rather than Shield's
    |   per-action convention.
    |
    | audience_resolver
    |   Fully-qualified class name of the bound BroadcastAudienceResolver.
    |   Drives the audience picker form, the recipient query, and the list-
    |   view summary. The shipped default lets admins pick recipients from
    |   a multi-select of users in the app's configured user model; host
    |   apps with richer targeting (roles, departments, locations, AppliesTo
    |   DSL, etc.) point this at their own implementation.
    |
    | release_pipeline
    |   Fully-qualified class name of the bound BroadcastReleasePipeline.
    |   Runs synchronously when an admin clicks the Publish action on a
    |   broadcast in a publishable status. The shipped default dispatches
    |   immediately (or with ->delay() when scheduled_at is set). Host apps
    |   wanting an approval gate, a moderation queue, or any other pre-send
    |   workflow bind their own pipeline — it decides what status the row
    |   transitions to and whether/when the dispatch job gets queued.
    |
    | audience_relation_manager
    |   Fully-qualified class name of the relation manager mounted on the
    |   broadcast view page. Defaults to the package's AudienceRelationManager
    |   (avatar + name + email + read/unread). Host apps that want to surface
    |   domain-specific columns (department, job title, location, role badges,
    |   …) extend the default class, override getColumns() or
    |   getAdditionalColumns(), and point this key at the subclass.
    |
    | chunk_size
    |   Number of recipients SendBroadcastJob loads per database round trip
    |   while fanning out. Defaults to 100; raise for installs with very
    |   large audiences (memory headroom permitting), lower for memory-
    |   constrained workers or to spread broadcast bursts more gently.
    |
    | model
    |   Fully-qualified Eloquent class Filament uses to hydrate, create, and
    |   list broadcast rows. Defaults to the package's own BroadcastNotification.
    |   Host apps that need the model to implement an external contract
    |   (e.g. an internal Approvable interface so a release pipeline can
    |   submit broadcasts through an existing approval engine) subclass the
    |   package model and point this key at the subclass. Most pipelines
    |   won't need this — the release pipeline contract receives the model
    |   as-is, so subclassing is only required when a third-party framework
    |   demands contract compliance on the model itself.
    |
    | view_page
    |   Fully-qualified Filament page class used for the broadcast's view
    |   route. Defaults to the package's ViewBroadcastNotification. Host apps
    |   that want to inject domain-specific sections into the view (approval
    |   progress panels, delivery analytics, audit trails, …) subclass the
    |   default page, override {@see \Filament\Resources\Pages\ViewRecord::infolist()}
    |   to append their extra components, and point this key at the subclass.
    |
    | initial_status
    |   Status a newly created broadcast lands in. Matches the 'draft' entry
    |   in `statuses` below out of the box.
    |
    | publishable_statuses
    |   Statuses from which the Publish action is enabled. Default workflow:
    |   only 'draft'. An approval-gated host would add 'approved' here so the
    |   admin can click Publish both from a fresh draft (to submit for
    |   approval) and from an approved row (to actually send).
    |
    | statuses
    |   The set of lifecycle states a broadcast row can be in, keyed by the
    |   string stored in the `status` column. Each entry declares:
    |     - label      Human-readable label for the status badge
    |     - color      Filament badge color ('gray', 'warning', 'success', …)
    |     - publishable  (optional) Convenience flag mirrored by the model
    |                    and resource so extending apps can mark statuses
    |                    publishable alongside publishable_statuses above.
    |   Host apps extend this map with their own workflow states
    |   ('pending_approval', 'approved', 'rejected', …) without touching
    |   any package code.
    |
    */

    'broadcaster' => [
        'permissions' => [
            'view_any' => 'ViewAny:BroadcastNotification',
            'view' => 'View:BroadcastNotification',
            'create' => 'Create:BroadcastNotification',
            'update' => 'Update:BroadcastNotification',
            'delete' => 'Delete:BroadcastNotification',
        ],

        'permission' => null,

        'model' => \Devletes\NotificationsMax\Models\BroadcastNotification::class,

        'view_page' => \Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages\ViewBroadcastNotification::class,

        'audience_resolver' => \Devletes\NotificationsMax\Defaults\AudienceResolver::class,

        'release_pipeline' => \Devletes\NotificationsMax\Defaults\ImmediateBroadcastReleasePipeline::class,

        'audience_relation_manager' => \Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\RelationManagers\AudienceRelationManager::class,

        'chunk_size' => 100,

        'initial_status' => 'draft',

        'publishable_statuses' => ['draft'],

        'statuses' => [
            'draft' => [
                'label' => 'Draft',
                'color' => 'gray',
                'publishable' => true,
            ],
            'queued' => [
                'label' => 'Queued',
                'color' => 'info',
                'publishable' => false,
            ],
            'scheduled' => [
                'label' => 'Scheduled',
                'color' => 'warning',
                'publishable' => false,
            ],
            'sent' => [
                'label' => 'Sent',
                'color' => 'success',
                'publishable' => false,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resolver Bindings
    |--------------------------------------------------------------------------
    |
    | Fully-qualified class names that implement the package's contracts. The
    | service provider reads this map at boot and binds each contract to the
    | named implementation via `bindIf`, so a manual `app()->bind()` in the
    | host's AppServiceProvider still wins when the host wants container-level
    | overrides (e.g. for tests).
    |
    |   tenant
    |     TenantResolver. The shipped default (FilamentTenantResolver)
    |     reads directly from Filament's manager facade and works for
    |     both single-tenant and Filament-tenancy installs without any
    |     host code. Override only when the host uses non-Filament
    |     tenancy (custom tenancy package, multi-database, etc.).
    |
    |   action_url
    |     ActionUrlBuilder. PathActionUrlBuilder is the neutral default and
    |     works for any app. Subdomain-per-tenant apps flip this to
    |     SubdomainActionUrlBuilder.
    |
    |   preference
    |     PreferenceResolver. The shipped Eloquent default reads the
    |     user_notification_preferences table and honours the type registry's
    |     mandatory flag. Override for quiet-hours, on-call, or non-DB
    |     preference stores.
    |
    */

    'resolvers' => [
        'tenant' => \Devletes\NotificationsMax\Defaults\FilamentTenantResolver::class,
        'action_url' => \Devletes\NotificationsMax\Defaults\PathActionUrlBuilder::class,
        'preference' => \Devletes\NotificationsMax\Defaults\EloquentPreferenceResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Context for Queue Workers
    |--------------------------------------------------------------------------
    |
    | The package's queue middleware
    | (Devletes\NotificationsMax\Queue\RestoreTenantContext) restores
    | Filament's tenant + Spatie's permission team automatically inside
    | SendBroadcastJob, so the host doesn't need to write any glue code
    | for queue-worker tenant context.
    |
    | panel
    |   Filament panel id whose tenant model the middleware should use
    |   when hydrating a tenant from the job's tenant_id. Leave null to
    |   auto-detect the first panel found in Filament::getPanels() with
    |   a tenant model declared — the right answer for the typical
    |   single-tenanted-panel install. Set this when more than one panel
    |   has tenancy and the auto-detected choice isn't the one you want.
    |
    */

    'tenant' => [
        'panel' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Global default applied when a notification type entry doesn't set its
    | own `rate_limit` key. Set `max` to 0 to disable limits globally.
    |
    */

    'rate_limits' => [
        'default' => [
            'max' => 0,         // 0 = unlimited
            'per_minutes' => 5,
        ],
    ],

];
