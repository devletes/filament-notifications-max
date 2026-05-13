<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Services;

use Devletes\NotificationsMax\Models\NotificationTypeOverride;
use Devletes\NotificationsMax\Registry\NotificationType;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;

/**
 * Single entry point for "what content / channels apply to this type for
 * this tenant right now". Encapsulates two orthogonal axes:
 *
 *   1. Content source — `config` (host's config/notifications.php) or
 *      `database` (notification_type_overrides table populated by
 *      `notifications-max:seed-content`). DB mode treats existing rows
 *      as authoritative (NULL fields fall back to config) and missing
 *      rows as fall back to config across the board.
 *
 *   2. Channel-aware content — each channel declares its own
 *      `content_fields` schema in `config('notifications-max.channels')`.
 *      Callers ask "give me content for channel X" and receive a
 *      field-keyed associative array — they don't need to know whether
 *      the channel is push, email, sms, or something a host added later.
 *
 * Render code (GenericNotification) consumes this; it never reads config
 * or the override table directly. Same goes for the preference resolver:
 * it asks for `allowedChannelsFor(...)` and gets the admin-overridden
 * (or config-default) list, with mandatory types short-circuiting to the
 * config list regardless of admin overrides.
 *
 * In-request memoisation keeps the override table reads to one query
 * per (tenant, type) per request — adequate for the typical
 * "dispatch-many-notifications-in-one-request" pattern without needing
 * a cache layer.
 *
 * @phpstan-type ChannelContent array<string, scalar|null>
 */
class NotificationContentResolver
{
    /**
     * @var array<string, ?NotificationTypeOverride>  cache key → override row
     */
    protected array $overrideCache = [];

    /**
     * Memoised result of the `content_source = database` config check.
     * Read on the hot path (every channel render); the underlying config()
     * call is cheap but called repeatedly per dispatch, so we cache it
     * on the resolver instance.
     */
    protected ?bool $useDatabaseCache = null;

    public function __construct(
        protected NotificationTypeRegistry $registry,
    ) {}

    /**
     * Channel content for a given type, in the shape the channel declared.
     *
     *   contentFor('approval.request.action_needed', 'push', 1)
     *     → ['title' => 'Approval needed for {approvable_label}', 'body' => '{approvable_summary}']
     *
     *   contentFor('approval.request.action_needed', 'email', 1)
     *     → ['subject' => '...', 'body' => '<p>...</p>', 'template' => 'default']
     *
     * Resolution order (per field):
     *   1. DB override (if `content_source = 'database'` and a row exists)
     *   2. Type config's `content[$channel]` block
     *   3. Top-level type config (`title`, `body`) as a back-compat
     *      fallback so types that never specified per-channel content
     *      still render correctly. For email the top-level `title` maps
     *      to the email `subject` and top-level `body` to email `body`.
     *
     * Returns only the fields declared by the channel in
     * `config('notifications-max.channels.{channel}.content_fields')`,
     * so caller can rely on the shape.
     *
     * @return ChannelContent
     */
    public function contentFor(string $typeKey, string $channel, ?int $tenantId): array
    {
        $type = $this->registry->find($typeKey);
        $channelDef = $this->channelDefinition($channel);
        $fields = array_keys($channelDef['content_fields'] ?? []);

        if ($fields === []) {
            return [];
        }

        $override = $this->shouldUseDatabase()
            ? $this->loadOverride($tenantId, $typeKey)
            : null;

        $configChannelContent = $type->content[$channel] ?? [];

        $resolved = [];

        foreach ($fields as $field) {
            $resolved[$field] = $this->resolveField(
                $field,
                $channel,
                $override,
                $configChannelContent,
                $type,
            );
        }

        return $resolved;
    }

    /**
     * Logical channels the dispatcher should consider firing for this
     * (tenant, type), already filtered by:
     *   - the type's mandatory flag (mandatory bypasses admin allowance)
     *   - the admin's `allowed_channels` override (DB mode only)
     *   - the type's config-level `allowed_channels`
     *
     * Caller (PreferenceResolver) further intersects with the user's
     * own toggles before final dispatch.
     *
     * @return array<int, string>
     */
    public function allowedChannelsFor(string $typeKey, ?int $tenantId): array
    {
        $type = $this->registry->find($typeKey);

        // Mandatory types are always at config level — admin can't disable
        // a compliance notification by mistake.
        if ($type->mandatory) {
            return $type->allowedChannels;
        }

        if (! $this->shouldUseDatabase()) {
            return $type->allowedChannels;
        }

        $override = $this->loadOverride($tenantId, $typeKey);

        if ($override === null || $override->allowed_channels === null) {
            return $type->allowedChannels;
        }

        // Admin's allowance is the floor — but never expose channels the
        // type doesn't actually support. Intersect with config to defend
        // against a stale override referencing a removed channel.
        return array_values(array_intersect($override->allowed_channels, $type->allowedChannels));
    }

    /**
     * Channel registry entry for a logical channel. Returns an empty
     * shape when the channel is unknown so callers can iterate without
     * special-casing.
     *
     * @return array{label?: string, physical?: array<int, string>, content_fields?: array<string, string>}
     */
    public function channelDefinition(string $channel): array
    {
        $def = config("notifications-max.channels.{$channel}");

        return is_array($def) ? $def : [];
    }

    /**
     * @return array<string, array<string, mixed>>  channel key → definition
     */
    public function allChannels(): array
    {
        $channels = config('notifications-max.channels', []);

        return is_array($channels) ? $channels : [];
    }

    public function shouldUseDatabase(): bool
    {
        return $this->useDatabaseCache ??= config('notifications-max.content_source') === 'database';
    }

    /**
     * The value the resolver would return for this (channel, field) when
     * no DB override is in play — i.e. the type's config-level value
     * with the same fallback chain {@see resolveField()} applies. Exposed
     * publicly so the admin settings page can compute "is this submitted
     * value identical to what config would produce?" without having to
     * duplicate the fallback rules.
     */
    public function configValueFor(NotificationType $type, string $channel, string $field): mixed
    {
        $configChannelContent = $type->content[$channel] ?? [];

        if (array_key_exists($field, $configChannelContent)) {
            return $configChannelContent[$field];
        }

        return $this->fallbackFromTopLevel($field, $channel, $type);
    }

    /**
     * Drop in-request override cache. Useful in tests / long-running
     * commands that mutate the table after first read.
     */
    public function flushCache(): void
    {
        $this->overrideCache = [];
        $this->useDatabaseCache = null;
    }

    protected function loadOverride(?int $tenantId, string $typeKey): ?NotificationTypeOverride
    {
        $cacheKey = ($tenantId ?? '_').'|'.$typeKey;

        if (array_key_exists($cacheKey, $this->overrideCache)) {
            return $this->overrideCache[$cacheKey];
        }

        return $this->overrideCache[$cacheKey] = NotificationTypeOverride::lookup($tenantId, $typeKey);
    }

    /**
     * Field-by-field resolution. DB override wins when its value is
     * non-null; null-on-DB-row falls back to channel config; channel
     * config absence falls back to top-level type config (where it
     * makes semantic sense — push title from top-level title, email
     * subject from top-level title, etc.).
     */
    protected function resolveField(
        string $field,
        string $channel,
        ?NotificationTypeOverride $override,
        array $configChannelContent,
        NotificationType $type,
    ): mixed {
        if ($override !== null) {
            $overrideValue = data_get($override->channel_content, "{$channel}.{$field}");

            if ($overrideValue !== null && $overrideValue !== '') {
                return $overrideValue;
            }
        }

        return $this->configValueFor($type, $channel, $field);
    }

    /**
     * Back-compat fallback for types that never opted into per-channel
     * content. Maps the top-level `title` / `body` to whichever channel
     * field roughly corresponds: `title` for push title and email subject;
     * `body` for push body and email body. Other fields (template, sms-
     * specific, etc.) have no fallback and resolve to null.
     */
    protected function fallbackFromTopLevel(string $field, string $channel, NotificationType $type): mixed
    {
        return match (true) {
            $field === 'title' => $type->title,
            $field === 'body' => $type->body,
            $field === 'subject' => $type->title,
            $field === 'template' => $this->defaultEmailTemplate(),
            default => null,
        };
    }

    protected function defaultEmailTemplate(): ?string
    {
        $templates = config('notifications-max.email_templates', []);

        if (! is_array($templates) || $templates === []) {
            return null;
        }

        return array_key_first($templates);
    }
}
