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
        public readonly string $label,
        public readonly string $description,
        public readonly string $title,
        public readonly string $body,
        public readonly string $icon,
        public readonly ?string $color,
        /**
         * Filament status — `success`, `warning`, `danger`, `info`, or null.
         * Mapped onto the Filament notification's `status()` so the bell entry
         * and toast pick up Filament's themed icon + color preset.
         */
        public readonly ?string $status,
        public readonly string $targetPanel,
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
            label: $config['label'] ?? $key,
            description: $config['description'] ?? '',
            title: $config['title'] ?? '',
            body: $config['body'] ?? '',
            icon: $config['icon'] ?? $defaults['icon'] ?? 'heroicon-o-bell',
            color: $config['color'] ?? null,
            status: $config['status'] ?? null,
            targetPanel: $config['target_panel'] ?? $defaults['target_panel'] ?? 'admin',
            actionResource: $config['action_resource'] ?? null,
            actionRecordKey: $config['action_record_key'] ?? null,
            duration: is_int($duration) || $duration === 'persistent' ? $duration : null,
            actions: array_values((array) ($config['actions'] ?? [])),
            defaultChannels: $config['default_channels'] ?? $defaults['default_channels'] ?? ['database', 'broadcast'],
            allowedChannels: $config['allowed_channels'] ?? $defaults['allowed_channels'] ?? ['database', 'broadcast', 'mail'],
            mandatory: (bool) ($config['mandatory'] ?? false),
            rateLimit: $config['rate_limit'] ?? null,
            notificationClass: $config['notification_class'] ?? null,
        );
    }

    /**
     * Returns true if the user is allowed to disable this channel via
     * their preferences (only when the type is not mandatory).
     */
    public function channelIsOptional(string $channel): bool
    {
        if ($this->mandatory) {
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
