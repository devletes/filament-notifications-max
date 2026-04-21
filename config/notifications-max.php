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
    | Channel Mapping (logical → physical)
    |--------------------------------------------------------------------------
    |
    | The notification-type registry and user preferences deal in *logical*
    | channels (what a user sees in the preferences UI): "push" and "email".
    |
    | At delivery time, the preference resolver expands those into the
    | *physical* channels Laravel actually uses:
    |
    |   - 'database'  persists a row in the notifications table (bell dropdown)
    |   - 'broadcast' fires a transient toast via Reverb (real-time popup)
    |   - 'mail'      sends an email
    |
    | "push" combines database + broadcast so users either get both or neither
    | — a toast without a corresponding bell entry would vanish forever,
    | which is never the intended UX. Consumers with exotic channel needs
    | (SMS, Slack, etc.) can add additional logical channels here and point
    | each at one or more Laravel notification channels.
    |
    */

    'channels' => [
        'push' => ['database', 'broadcast'],
        'email' => ['mail'],
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
    | Admin Role (for SpatieAdminRoleResolver)
    |--------------------------------------------------------------------------
    |
    | Spatie role name treated as "admin" by the default admin role resolver.
    | Used to decide who can operate the admin broadcaster and which users
    | receive "CC admins" dispatches. Ignored if the host binds a custom
    | AdminRoleResolver.
    |
    */

    'admin_role' => 'admin',

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

    'broadcaster' => [
        'permission' => 'broadcast-notifications',
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
