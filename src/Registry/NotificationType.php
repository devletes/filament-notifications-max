<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Registry;

/**
 * Value object describing a single notification type. Built from an entry
 * in the host app's `config/notifications.php` registry.
 *
 * All fields are public-readable for convenience; they should be treated
 * as immutable (construct a new instance to change anything).
 */
final class NotificationType
{
    public function __construct(
        public readonly string $key,
        public readonly string $category,
        /**
         * Optional sub-category for the preferences UI. When several types
         * in the same `category` share a `group`, the preferences page
         * collapses them into one fieldset (e.g. "Requests") with one row
         * per type and inline channel toggles — instead of rendering N
         * separate fieldsets. Types that omit `group` fall back to the
         * per-type fieldset layout.
         */
        public readonly ?string $group,
        /**
         * Row label shown inside a group's fieldset. Typically a short
         * action phrase ("Needs your approval", "Rejected", …) as opposed
         * to the full {@see self::$label} which often repeats the subject
         * ("Request rejected"). Ignored when the type has no {@see $group}.
         */
        public readonly ?string $groupLabel,
        public readonly string $label,
        public readonly string $description,
        /**
         * Top-level `title` and `body` are kept as a convenience fallback
         * for types that don't declare per-channel content. The content
         * resolver maps these onto whichever channel field makes sense
         * (push title, email subject, etc.) so simple types can declare a
         * single title/body and have it work everywhere.
         */
        public readonly string $title,
        public readonly string $body,
        /**
         * Per-channel content overrides keyed by channel:
         *
         *   'content' => [
         *       'push'  => ['title' => '...', 'body' => '...'],
         *       'email' => ['subject' => '...', 'body' => '<p>...</p>', 'template' => 'default'],
         *   ]
         *
         * Field names are declared by each channel's `content_fields`
         * entry in `config('notifications-max.channels')`. Any field a
         * channel needs but the type doesn't supply falls back to the
         * top-level `title`/`body` (where semantically meaningful — see
         * `NotificationContentResolver::fallbackFromTopLevel()`).
         *
         * @var array<string, array<string, mixed>>
         */
        public readonly array $content,
        public readonly string $icon,
        public readonly ?string $color,
        /**
         * Filament status — `success`, `warning`, `danger`, `info`, or null.
         * Mapped onto the Filament notification's `status()` so the bell entry
         * and toast pick up Filament's themed icon + color preset.
         */
        public readonly ?string $status,
        public readonly string $targetPanel,
        /**
         * Panels that can render this notification's record. Null = no
         * registry-level constraint (polymorphic types — dispatch site
         * supplies the address).
         *
         * @var array<int, string>|null
         */
        public readonly ?array $panels,
        public readonly ?string $actionResource,
        public readonly ?string $actionRecordKey,
        /**
         * Toast auto-dismiss duration in milliseconds, or the string
         * `'persistent'` to disable auto-dismiss. Null = use the package
         * default (`notifications-max.toast.duration`).
         *
         * @var int|string|null
         */
        public readonly int|string|null $duration,
        /**
         * Optional explicit action specs. Each entry is an associative array:
         *
         *   [
         *     'name'         => 'view',           // required
         *     'label'        => 'Open invoice',   // optional, supports {placeholders}
         *     'url'          => '{invoice_url}',  // optional, supports {placeholders}
         *     'color'        => 'primary',        // optional Filament color
         *     'icon'         => 'heroicon-o-eye', // optional
         *     'mark_as_read' => true,             // default true
         *     'open_in_new'  => false,            // default false
         *   ]
         *
         * When this array is empty AND `actionResource` is set, GenericNotification
         * synthesises a single legacy-style "view" action that delegates to the
         * `ActionUrlBuilder` contract. Both paths can be used together: explicit
         * actions render in declaration order; the legacy fallback only fires
         * when no actions are declared.
         *
         * @var array<int, array<string, mixed>>
         */
        public readonly array $actions,
        /** @var array<int, string> */
        public readonly array $defaultChannels,
        /** @var array<int, string> */
        public readonly array $allowedChannels,
        public readonly bool $mandatory,
        /**
         * Sender-driven types. The dispatcher honours admin-supplied
         * `context.channels` (and/or the type's `allowed_channels` when no
         * per-message list is supplied) and bypasses per-user preferences.
         * The preferences UI hides these types entirely — users can't
         * meaningfully toggle channels that the sender re-picks on every
         * dispatch. Distinct from {@see $mandatory}: mandatory types always
         * fire on dispatch; admin_controlled types may not even be
         * dispatched (the admin decides).
         */
        public readonly bool $adminControlled,
        /** @var array{max:int, per_minutes:int}|null */
        public readonly ?array $rateLimit,
        /**
         * Optional FQCN of a `Illuminate\Notifications\Notification` subclass
         * to use for this type instead of the default `GenericNotification`.
         * Lets host apps build per-type templates / channels / queue policy
         * while still routing through the dispatcher and registry.
         */
        public readonly ?string $notificationClass,
    ) {}

    /**
     * @param  array<string, mixed>  $config  One entry from the host app's notification type registry
     */
    public static function fromConfig(string $key, array $config): self
    {
        $defaults = config('notifications-max.type_defaults', []);

        $duration = $config['duration'] ?? null;

        return new self(
            key: $key,
            category: $config['category'] ?? $defaults['category'] ?? 'general',
            group: isset($config['group']) && $config['group'] !== '' ? (string) $config['group'] : null,
            groupLabel: isset($config['group_label']) && $config['group_label'] !== '' ? (string) $config['group_label'] : null,
            label: $config['label'] ?? $key,
            description: $config['description'] ?? '',
            title: $config['title'] ?? '',
            body: $config['body'] ?? '',
            content: is_array($config['content'] ?? null) ? $config['content'] : [],
            icon: $config['icon'] ?? $defaults['icon'] ?? 'heroicon-o-bell',
            color: $config['color'] ?? null,
            status: $config['status'] ?? null,
            targetPanel: $config['target_panel'] ?? $defaults['target_panel'] ?? 'admin',
            panels: self::normalisePanels($config, $defaults),
            actionResource: $config['action_resource'] ?? null,
            actionRecordKey: $config['action_record_key'] ?? null,
            duration: is_int($duration) || $duration === 'persistent' ? $duration : null,
            actions: array_values((array) ($config['actions'] ?? [])),
            // Logical channel names — match the rest of the package and the
            // `type_defaults` block in config. Physical-channel expansion
            // (push → database+broadcast, email → mail, …) happens later in
            // EloquentPreferenceResolver::expandLogicalChannels().
            defaultChannels: $config['default_channels'] ?? $defaults['default_channels'] ?? ['push'],
            allowedChannels: $config['allowed_channels'] ?? $defaults['allowed_channels'] ?? ['push', 'email'],
            mandatory: (bool) ($config['mandatory'] ?? false),
            adminControlled: (bool) ($config['admin_controlled'] ?? false),
            rateLimit: $config['rate_limit'] ?? null,
            notificationClass: $config['notification_class'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $defaults
     * @return array<int, string>|null
     */
    protected static function normalisePanels(array $config, array $defaults): ?array
    {
        $raw = array_key_exists('panels', $config)
            ? $config['panels']
            : ($defaults['panels'] ?? null);

        if ($raw === null) {
            return null;
        }

        if (is_string($raw)) {
            $raw = [$raw];
        }

        if (! is_array($raw)) {
            return null;
        }

        $clean = array_values(array_filter(
            array_map(fn ($p) => is_string($p) ? trim($p) : '', $raw),
            fn (string $p) => $p !== '',
        ));

        return $clean === [] ? null : $clean;
    }

    /**
     * Returns true if the user is allowed to disable this channel via
     * their preferences (only when the type is not mandatory).
     */
    public function channelIsOptional(string $channel): bool
    {
        if ($this->mandatory || $this->adminControlled) {
            return false;
        }

        return in_array($channel, $this->allowedChannels, true);
    }

    /**
     * Returns true if the channel is enabled by default (when the user has
     * no explicit preference row).
     */
    public function channelIsOnByDefault(string $channel): bool
    {
        return in_array($channel, $this->defaultChannels, true);
    }
}
