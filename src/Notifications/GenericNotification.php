<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Notifications;

use Devletes\NotificationsMax\Contracts\ActionUrlBuilder;
use Devletes\NotificationsMax\Contracts\ChannelHandler;
use Devletes\NotificationsMax\Contracts\PreferenceResolver;
use Devletes\NotificationsMax\Registry\NotificationType;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The one and only notification class in notifications-max.
 *
 * Parameterized by a type key + context array; every rendering concern
 * (title, body, icon, color, target panel, action resource, channels,
 * mandatoriness) comes from the host app's config('notifications') registry.
 *
 * Adding a new notification type = adding a row to that registry. No new
 * PHP class.
 *
 * ## Channel methods
 *
 * Per-channel rendering lives in handler classes (see {@see ChannelHandler}).
 * Each `to{Channel}()` method on this class is a one-line delegate that
 * resolves the channel's handler from `notifications-max.channel_handlers`
 * config and forwards the call. Real methods (rather than `__call` magic)
 * are required because Laravel's built-in channels — and most third-party
 * channel classes — gate their dispatch with `method_exists()` checks.
 *
 * To add a channel beyond those shipped, subclass this notification, add
 * a `to{Channel}()` method, and configure the subclass via
 * `notifications-max.default_notification_class`.
 *
 * ## Queueing
 *
 * NOT `ShouldQueue` by design. All channel work runs synchronously inside
 * the caller's `Notification::send()` call so transient channel failures
 * (notably `BroadcastException` when Reverb is unreachable) land in
 * {@see \Devletes\NotificationsMax\Services\NotificationDispatcher::send()}
 * and degrade to a logged warning instead of a 500. If a host wants async
 * delivery, they declare a per-type `notification_class` that extends this
 * one and opts into `ShouldQueue`.
 */
class GenericNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $context  Placeholders + routing data
     */
    public function __construct(
        public readonly string $typeKey,
        public readonly array $context = [],
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return app(PreferenceResolver::class)
            ->channelsFor($notifiable, $this->typeKey);
    }

    // ─── Channel delegates ─────────────────────────────────────────────
    //
    // Each method below is a real method (not `__call`-routed) so that
    // Laravel's `method_exists`-based channel dispatch works. The body
    // delegates to the configured handler class for the channel.
    //
    // Built-in channels (database / broadcast / mail) keep their concrete
    // Laravel return types — those classes are always available. Optional
    // channels (twilio / vonage / slack / discord) return `mixed` so the
    // method signature parses even when the corresponding third-party
    // notification package isn't installed; the third-party Message type
    // is only required at runtime, inside the handler class, after the
    // host has opted into that channel.

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->dispatchChannel('database', $notifiable);
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return $this->dispatchChannel('broadcast', $notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->dispatchChannel('mail', $notifiable);
    }

    public function toTwilio(object $notifiable): mixed
    {
        return $this->dispatchChannel('twilio', $notifiable);
    }

    public function toVonage(object $notifiable): mixed
    {
        return $this->dispatchChannel('vonage', $notifiable);
    }

    public function toSlack(object $notifiable): mixed
    {
        return $this->dispatchChannel('slack', $notifiable);
    }

    public function toDiscord(object $notifiable): mixed
    {
        return $this->dispatchChannel('discord', $notifiable);
    }

    /**
     * Look up the registered handler for a physical channel name and
     * forward the dispatch. Centralised so a host customising a single
     * channel's rendering (e.g. swapping `DatabaseChannelHandler` for
     * a subclass that includes extra audit fields) only has to point
     * `notifications-max.channel_handlers.database` at their class —
     * no per-method override required.
     */
    protected function dispatchChannel(string $channel, object $notifiable): mixed
    {
        $handlerClass = config("notifications-max.channel_handlers.{$channel}");

        if (! is_string($handlerClass) || ! class_exists($handlerClass)) {
            throw new RuntimeException(sprintf(
                'No channel handler registered for [%s]. '.
                'Map a handler class at notifications-max.channel_handlers.%s.',
                $channel,
                $channel,
            ));
        }

        /** @var ChannelHandler $handler */
        $handler = app($handlerClass);

        return $handler->send($notifiable, $this);
    }

    // ─── Broadcast id override (separate from channel routing) ─────────

    /**
     * Override Laravel's `BroadcastNotificationCreated` id-merge so the
     * toast payload uses our generated UUID instead of the DB row id.
     * When this method exists on a notification, Laravel uses its return
     * value verbatim for the wire payload — no
     * `array_merge([..., 'id' => $notification->id])`.
     *
     * Kept hardcoded (not routed through a handler) because it's a
     * Laravel wire-payload override, not a channel-level concern.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->resolveBroadcastData();
    }

    // ─── Public handler API ────────────────────────────────────────────
    //
    // The methods below are the documented surface channel handlers
    // (and host subclasses) can lean on. Promoting them from `protected`
    // to `public` formalises the contract: handlers may call any of
    // these; anything else on this class is internal.

    /**
     * Build the shared Filament-formatted payload used by both the
     * database channel (bell dropdown) and the broadcast channel (toast).
     * Delegates to Filament's native `getDatabaseMessage()` so the payload
     * matches what Filament's own `sendToDatabase()` produces — in
     * particular, it forces `duration: 'persistent'` (overriding
     * HasDuration's 6000ms default) and unsets the internal id. Without
     * that, bell dropdown items would auto-close 6 seconds after
     * rendering and fire `notificationClosed`, which the bell component
     * turns into a DELETE against the DB row.
     *
     * {@see resolveBroadcastData()} starts from this base and sets a
     * numeric duration so the toast auto-dismisses, plus a fresh UUID
     * for the toast id.
     *
     * @return array<string, mixed>
     */
    public function buildFilamentPayload(): array
    {
        $type = $this->resolveType();

        // Push channel content — admin overrides win over config when
        // the package is in database mode; resolver handles precedence.
        $push = app(\Devletes\NotificationsMax\Services\NotificationContentResolver::class)
            ->contentFor($type->key, 'push', $this->resolveTenantId());

        $filament = FilamentNotification::make()
            ->title($this->render((string) ($push['title'] ?? $type->title)))
            ->body($this->render((string) ($push['body'] ?? $type->body)))
            ->icon($type->icon);

        if ($type->color) {
            $filament->iconColor($type->color);
        }

        if ($type->status) {
            // Maps to Filament's themed icon + color preset for the
            // success/warning/danger/info variants.
            $filament->status($type->status);
        }

        $actions = $this->buildActions($type);

        if ($actions !== []) {
            $filament->actions($actions);
        }

        $payload = array_merge(
            // `getDatabaseMessage()` forces duration='persistent' and
            // sets format='filament'; we inherit both instead of
            // duplicating them.
            $filament->getDatabaseMessage(),
            [
                '_meta' => [
                    'type_key' => $this->typeKey,
                    'target_panel' => $type->targetPanel,
                    'tenant_slug' => $this->context['tenant_slug'] ?? null,
                ],
            ],
        );

        // Stamp the originating broadcast id at the top of the payload
        // so the admin-side audience table's read/unread subquery can
        // find matching rows via `data->broadcast_id` without having to
        // walk into `_meta`. Only written for notifications dispatched
        // from a `BroadcastNotification` — every other call site leaves
        // this key absent.
        if (isset($this->context['broadcast_id'])) {
            $payload['broadcast_id'] = $this->context['broadcast_id'];
        }

        return $payload;
    }

    /**
     * Build the toast payload. Cached per-instance so
     * {@see BroadcastChannelHandler} and {@see broadcastWith()} return
     * the same id.
     *
     * @return array<string, mixed>
     */
    public function resolveBroadcastData(): array
    {
        $data = $this->buildFilamentPayload();
        $data['duration'] = $this->resolveToastDuration();
        $data['id'] = $this->getBroadcastId();

        return $data;
    }

    public function resolveType(): NotificationType
    {
        return app(NotificationTypeRegistry::class)->find($this->typeKey);
    }

    /**
     * Tenant scope for content lookups. Pulled from context (set by the
     * dispatcher's enrichContext) when present, falling back to the
     * bound TenantResolver. Returns null in single-tenant installs.
     */
    public function resolveTenantId(): ?int
    {
        if (isset($this->context['tenant_id'])) {
            return is_numeric($this->context['tenant_id']) ? (int) $this->context['tenant_id'] : null;
        }

        return app(\Devletes\NotificationsMax\Contracts\TenantResolver::class)->currentId();
    }

    /**
     * Naive template renderer: replaces `{placeholder}` tokens in a
     * string with values from {@see $context}. Missing placeholders are
     * left intact so debugging is easier.
     */
    public function render(string $template): string
    {
        if ($template === '') {
            return '';
        }

        return Str::of($template)
            ->replaceMatches('/\{([a-zA-Z0-9_\.]+)\}/', function (array $m): string {
                $key = $m[1];
                $value = data_get($this->context, $key);

                if (is_scalar($value)) {
                    return (string) $value;
                }

                // Preserve the placeholder for missing keys.
                return '{'.$key.'}';
            })
            ->toString();
    }

    /**
     * Build the array of Filament Actions to attach to the notification.
     * Source order:
     *   1. Explicit `actions` array on the registry entry (declared spec)
     *   2. Legacy single-action synthesised from `action_resource` /
     *      `action_record_key` / `context.action_url` (back-compat)
     *
     * @return array<int, Action>
     */
    public function buildActions(NotificationType $type): array
    {
        if ($type->actions !== []) {
            return collect($type->actions)
                ->map(fn (array $spec): ?Action => $this->buildActionFromSpec($spec, $type))
                ->filter()
                ->values()
                ->all();
        }

        $url = $this->buildLegacyActionUrl($type);

        if ($url === null) {
            return [];
        }

        return [
            Action::make('view')
                ->label(__($this->resolveActionLabel($type)))
                ->url($url)
                ->markAsRead(),
        ];
    }

    /**
     * Back-compat URL builder used when the registry entry doesn't
     * declare an explicit `actions` array. Resolves a single URL from
     * `action_resource` + `action_record_key` (via the
     * {@see ActionUrlBuilder} contract), or honours an explicit
     * `context.action_url` override.
     */
    public function buildLegacyActionUrl(NotificationType $type): ?string
    {
        if (isset($this->context['action_url'])) {
            return $this->context['action_url'];
        }

        if ($type->actionResource === null) {
            return null;
        }

        $recordKey = $type->actionRecordKey;
        $recordId = $recordKey && isset($this->context[$recordKey])
            ? $this->context[$recordKey]
            : null;

        if ($recordId === null) {
            return null;
        }

        return app(ActionUrlBuilder::class)->build(
            panelId: $type->targetPanel,
            resourceSlug: $type->actionResource,
            recordId: $recordId,
            context: $this->context,
        );
    }

    public function resolveActionLabel(NotificationType $type): string
    {
        return $this->context['action_label']
            ?? config('notifications-max.default_action_label', 'View');
    }

    // ─── Internal helpers ──────────────────────────────────────────────

    /**
     * Translate a single action spec from the registry into a Filament
     * Action. Returns null when the spec lacks a usable URL — silently
     * skipping keeps malformed entries from breaking the whole
     * notification.
     *
     * @param  array<string, mixed>  $spec
     */
    protected function buildActionFromSpec(array $spec, NotificationType $type): ?Action
    {
        $name = is_string($spec['name'] ?? null) ? $spec['name'] : 'action';

        $action = Action::make($name);

        $label = $spec['label'] ?? null;

        if (is_string($label)) {
            $action->label(__($this->render($label)));
        } else {
            $action->label(__($this->resolveActionLabel($type)));
        }

        if (isset($spec['color']) && is_string($spec['color'])) {
            $action->color($spec['color']);
        }

        if (isset($spec['icon']) && is_string($spec['icon'])) {
            $action->icon($spec['icon']);
        }

        $url = isset($spec['url']) && is_string($spec['url'])
            ? $this->render($spec['url'])
            : $this->buildLegacyActionUrl($type);

        if ($url === null || $url === '') {
            return null;
        }

        $openInNew = (bool) ($spec['open_in_new'] ?? false);

        $action->url($url, shouldOpenInNewTab: $openInNew);

        if ($spec['mark_as_read'] ?? true) {
            $action->markAsRead();
        }

        return $action;
    }

    /**
     * Per-type toast duration → package config default → 5000ms
     * hardcoded fallback. Accepts an integer (ms) or the string
     * `'persistent'`.
     */
    protected function resolveToastDuration(): int|string
    {
        $type = $this->resolveType();

        if ($type->duration !== null) {
            return $type->duration;
        }

        $configured = config('notifications-max.toast.duration', 5000);

        return is_int($configured) || $configured === 'persistent' ? $configured : 5000;
    }

    protected ?string $broadcastId = null;

    protected function getBroadcastId(): string
    {
        return $this->broadcastId ??= (string) Str::uuid();
    }
}
