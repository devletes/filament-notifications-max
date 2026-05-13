<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Notifications;

use Devletes\NotificationsMax\Contracts\ActionUrlBuilder;
use Devletes\NotificationsMax\Contracts\PreferenceResolver;
use Devletes\NotificationsMax\Registry\NotificationType;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

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
 * NOT `ShouldQueue` by design. All channel work (database persist, toast
 * broadcast, email send) runs synchronously inside the caller's
 * `Notification::send()` call, so any channel failure — notably a
 * BroadcastException when Reverb is unreachable — lands in
 * {@see \Devletes\NotificationsMax\Services\NotificationDispatcher::send()}'s
 * try/catch and degrades to a logged warning instead of a 500. If a host
 * wants async delivery of a specific channel (e.g. batched email), they
 * can declare a per-type `notification_class` that extends this one and
 * opts into `ShouldQueue`.
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


    /**
     * Database channel payload. Filament's DatabaseNotifications Livewire
     * component reads rows where `data->format = 'filament'` and rebuilds
     * a FilamentNotification via ::fromArray(). By constructing one here
     * and serializing it we get parity with native Filament serialization
     * (actions, icon/color/duration handling, etc.) for free.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->buildFilamentPayload();
    }

    /**
     * Build the shared Filament-formatted payload used by both the database
     * channel (bell dropdown) and the broadcast channel (toast). We delegate
     * to Filament's native `getDatabaseMessage()` so the payload matches what
     * Filament's own `sendToDatabase()` produces — in particular, it forces
     * `duration: 'persistent'` (overriding HasDuration's 6000ms default) and
     * unsets the internal id. Without that, bell dropdown items would auto-
     * close 6 seconds after rendering and fire `notificationClosed`, which
     * the bell component turns into a DELETE against the DB row.
     *
     * `toBroadcast()` starts from this base and sets a numeric `duration` so
     * the toast auto-dismisses — the toast and the DB row SHARE an id, which
     * is intentional: dismissing the toast marks the bell row as read via
     * our overridden DatabaseNotifications Livewire component (which updates
     * `read_at` instead of deleting).
     *
     * @return array<string, mixed>
     */
    protected function buildFilamentPayload(): array
    {
        $type = $this->resolveType();

        // Push channel content — admin overrides win over config when the
        // package is in database mode; resolver handles the precedence.
        $push = app(NotificationContentResolver::class)
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
            // `getDatabaseMessage()` forces duration='persistent' and sets
            // format='filament'; we inherit both instead of duplicating.
            $filament->getDatabaseMessage(),
            [
                '_meta' => [
                    'type_key' => $this->typeKey,
                    'target_panel' => $type->targetPanel,
                    'tenant_slug' => $this->context['tenant_slug'] ?? null,
                ],
            ],
        );

        // Stamp the originating broadcast id at the top of the payload so
        // the admin-side audience table's read/unread subquery can find
        // matching rows via `data->broadcast_id` without having to walk
        // into `_meta`. Only written for notifications dispatched from a
        // `BroadcastNotification` — every other call site leaves this
        // key absent.
        if (isset($this->context['broadcast_id'])) {
            $payload['broadcast_id'] = $this->context['broadcast_id'];
        }

        return $payload;
    }

    /**
     * Broadcast channel payload (the transient toast).
     *
     * The toast id is intentionally DECOUPLED from the database row id (see
     * {@see broadcastWith()}). Filament's default bell-panel Livewire reacts
     * to the `notificationClosed` window event by deleting the row whose id
     * matches — desirable for an explicit X click in the bell, undesirable
     * for a toast (where the same `close()` is called both on user X click
     * and on auto-dismiss). Giving the toast its own id means neither path
     * accidentally deletes the persisted row, leaving it in the bell as
     * unread until the user acts on it from the panel.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->resolveBroadcastData());
    }

    /**
     * Override Laravel's BroadcastNotificationCreated id-merge so the toast
     * payload uses our generated UUID instead of the DB row id. When this
     * method exists on a notification, Laravel uses its return value verbatim
     * for the wire payload — no `array_merge([..., 'id' => $notification->id])`.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->resolveBroadcastData();
    }

    /**
     * Build the toast payload. Cached per-instance so that {@see toBroadcast()}
     * and {@see broadcastWith()} return the same id.
     *
     * @return array<string, mixed>
     */
    protected function resolveBroadcastData(): array
    {
        $data = $this->buildFilamentPayload();
        $data['duration'] = $this->resolveToastDuration();
        $data['id'] = $this->getBroadcastId();

        return $data;
    }

    protected ?string $broadcastId = null;

    protected function getBroadcastId(): string
    {
        return $this->broadcastId ??= (string) Str::uuid();
    }

    /**
     * Per-type toast duration → package config default → 5000ms hardcoded
     * fallback. Accepts an integer (ms) or the string `'persistent'`.
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

    /**
     * Minimal email payload. Host apps can override the default view via
     * the `notifications-max.mail.view` config, or publish the package view
     * for deep customization. Per-type templates can be layered in later
     * phases by reading `$type` metadata.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $type = $this->resolveType();

        // Email channel content — same resolver handles config-vs-DB
        // precedence. The shape returned matches `content_fields` declared
        // for the email channel: subject, body (HTML), template name.
        $email = app(NotificationContentResolver::class)
            ->contentFor($type->key, 'email', $this->resolveTenantId());

        $subject = $this->render((string) ($email['subject'] ?? $type->title));
        $body = $this->render((string) ($email['body'] ?? $type->body));
        $templateName = (string) ($email['template'] ?? '');

        $message = (new MailMessage)->subject($subject);

        // Resolve the chosen email template's Blade view. Falls back to
        // Laravel's default "line" rendering when the host hasn't
        // registered any templates or chose an unknown one — keeps the
        // package working out-of-the-box without forcing a template
        // registry to be configured.
        $templateView = $this->resolveEmailTemplateView($templateName);

        if ($templateView !== null) {
            $message->view($templateView, [
                'subject' => $subject,
                'content' => $body,
                'recipient' => $notifiable,
                'type' => $type,
            ]);
        } else {
            $message->line($body);
        }

        // For mail we collapse the (possibly multi-) action list to its first
        // entry — Laravel's MailMessage convention is one primary action.
        $actions = $this->buildActions($type);

        if ($actions === [] && $type->actionResource !== null) {
            if ($url = $this->buildLegacyActionUrl($type)) {
                $message->action($this->resolveActionLabel($type), $url);
            }
        } elseif ($actions !== []) {
            $first = $actions[0];
            $url = $first->getUrl();

            if ($url) {
                $message->action($first->getLabel() ?? $this->resolveActionLabel($type), $url);
            }
        }

        return $message;
    }

    /**
     * Resolve a registered email template name to its Blade view path.
     *
     * Resolution:
     *   - No templates configured     → null (caller renders a line-style mail)
     *   - Empty name (no preference)  → first registered template (default)
     *   - Name found in the registry  → that template
     *   - Name NOT found              → null (fall back to line-style mail)
     *
     * The not-found case previously silently fell back to the FIRST
     * registered template, which masked misspelled template names — the
     * email rendered fine but with the wrong template. Returning null
     * instead routes through MailMessage's default `->line()` rendering
     * so the email still sends but the typo is visible in QA.
     */
    protected function resolveEmailTemplateView(string $name): ?string
    {
        $templates = config('notifications-max.email_templates', []);

        if (! is_array($templates) || $templates === []) {
            return null;
        }

        if ($name === '') {
            return (string) reset($templates);
        }

        return isset($templates[$name]) ? (string) $templates[$name] : null;
    }

    /**
     * Tenant scope for content lookups. Pulled from context (set by the
     * dispatcher's enrichContext) when present, falling back to the bound
     * TenantResolver. Returns null in single-tenant installs.
     */
    protected function resolveTenantId(): ?int
    {
        if (isset($this->context['tenant_id'])) {
            return is_numeric($this->context['tenant_id']) ? (int) $this->context['tenant_id'] : null;
        }

        return app(\Devletes\NotificationsMax\Contracts\TenantResolver::class)->currentId();
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
    protected function buildActions(NotificationType $type): array
    {
        if ($type->actions !== []) {
            return collect($type->actions)
                ->map(fn (array $spec) => $this->buildActionFromSpec($spec, $type))
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
     * Translate a single action spec from the registry into a Filament Action.
     * Returns null when the spec lacks a usable URL — silently skipping
     * keeps malformed entries from breaking the whole notification.
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

    protected function resolveType(): NotificationType
    {
        return app(NotificationTypeRegistry::class)->find($this->typeKey);
    }

    /**
     * Back-compat URL builder used when the registry entry doesn't declare
     * an explicit `actions` array. Resolves a single URL from
     * `action_resource` + `action_record_key` (via the ActionUrlBuilder
     * contract), or honours an explicit `context.action_url` override.
     */
    protected function buildLegacyActionUrl(NotificationType $type): ?string
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

    protected function resolveActionLabel(NotificationType $type): string
    {
        return $this->context['action_label']
            ?? config('notifications-max.default_action_label', 'View');
    }

    /**
     * Naive template renderer: replaces `{placeholder}` tokens in the
     * configured title/body strings with values from $context. Missing
     * placeholders are left intact so debugging is easier.
     */
    protected function render(string $template): string
    {
        if ($template === '') {
            return '';
        }

        return Str::of($template)
            ->replaceMatches('/\{([a-zA-Z0-9_\.]+)\}/', function (array $m) {
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
}
