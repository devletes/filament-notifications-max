<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Registry;

use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Read-only accessor around the host app's notification type definitions.
 *
 * Sources, in load order:
 *   1. The config key named in `notifications-max.types_config_key`
 *      (default `notifications`, i.e. `config/notifications.php`).
 *   2. Runtime registrations via {@see register()} / {@see registerMany()},
 *      which override config-defined keys when both are present.
 *
 * Runtime registration lets third-party packages ship their own type catalog
 * without forcing the consumer to copy entries into their config file.
 *
 * The registry is lazy: config is parsed on first access and cached for the
 * request lifetime. Runtime registrations are layered on top and survive the
 * cache by virtue of being stored in the same `$types` array.
 */
class NotificationTypeRegistry
{
    /** @var array<string, NotificationType>|null */
    protected ?array $types = null;

    /**
     * Definitions queued by register() before the first all() call. Merged
     * into $types on warmup so they survive the lazy load.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $pending = [];

    public function find(string $key): NotificationType
    {
        $type = $this->all()[$key] ?? null;

        if ($type === null) {
            throw new RuntimeException(
                "Notification type [{$key}] is not registered. Add it to your types config or call NotificationTypeRegistry::register()."
            );
        }

        return $type;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * @return array<string, NotificationType>
     */
    public function all(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $configKey = config('notifications-max.types_config_key', 'notifications');
        $config = config($configKey, []);

        // Accept either a flat map (key => definition) or a nested
        // `types` key — prefer the nested form when both exist.
        $raw = is_array($config) && array_key_exists('types', $config)
            ? $config['types']
            : $config;

        $types = [];

        foreach ((array) $raw as $key => $definition) {
            if (! is_string($key) || ! is_array($definition)) {
                continue;
            }

            $types[$key] = NotificationType::fromConfig($key, $definition);
        }

        // Layer runtime registrations on top — they win on key collision.
        foreach ($this->pending as $key => $definition) {
            $types[$key] = NotificationType::fromConfig($key, $definition);
        }

        $this->pending = [];

        return $this->types = $types;
    }

    /**
     * Register a single notification type at runtime. Overrides any same-key
     * config entry.
     *
     * @param  array<string, mixed>  $definition
     */
    public function register(string $key, array $definition): void
    {
        if ($this->types === null) {
            // Cache hasn't been warmed yet; queue and let all() merge it in.
            $this->pending[$key] = $definition;

            return;
        }

        $this->types[$key] = NotificationType::fromConfig($key, $definition);
    }

    /**
     * Bulk runtime registration.
     *
     * @param  array<string, array<string, mixed>>  $map
     */
    public function registerMany(array $map): void
    {
        foreach ($map as $key => $definition) {
            $this->register($key, $definition);
        }
    }

    /**
     * @return Collection<int, NotificationType>
     */
    public function byCategory(string $category): Collection
    {
        return collect($this->all())
            ->filter(fn (NotificationType $t) => $t->category === $category)
            ->values();
    }

    /**
     * @return array<int, string>
     */
    public function mandatoryKeys(): array
    {
        return collect($this->all())
            ->filter(fn (NotificationType $t) => $t->mandatory)
            ->keys()
            ->all();
    }

    /**
     * Flush the cache. Runtime registrations are NOT preserved — call
     * register()/registerMany() again after flushing if needed. Mainly for
     * tests where the config changes mid-request.
     */
    public function flush(): void
    {
        $this->types = null;
        $this->pending = [];
    }
}
