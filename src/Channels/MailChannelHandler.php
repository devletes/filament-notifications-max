<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Channels;

use Devletes\NotificationsMax\Contracts\ChannelHandler;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Renders the MailMessage Laravel's mail channel passes to the configured
 * mailer. Email content comes from {@see NotificationContentResolver} so
 * the same DB-override / config / top-level-fallback chain that drives
 * other channels also drives the mail subject and body.
 *
 * Template selection: each type entry can declare a template name via its
 * channel content (`content.email.template`). The handler maps the name
 * to a Blade view through `notifications-max.email_templates`. Unknown
 * names fall back to Laravel's line-style `MailMessage` rendering so
 * misconfigured names show up as plain emails in QA rather than silently
 * rendering with the wrong template.
 */
class MailChannelHandler implements ChannelHandler
{
    public function __construct(
        protected NotificationContentResolver $contentResolver,
    ) {}

    public function send(object $notifiable, GenericNotification $notification): MailMessage
    {
        $type = $notification->resolveType();

        $email = $this->contentResolver->contentFor(
            $type->key,
            'email',
            $notification->resolveTenantId(),
        );

        // Subject is plain text per RFC 5322 — mail transports header-encode
        // it on the wire. Render with the default 'plain' richness so
        // interpolated values pass through verbatim; no HTML escaping.
        $subject = $notification->render((string) ($email['subject'] ?? $type->title));

        // Body renders as HTML. The template (config or admin-authored via
        // the rich editor) is trusted; interpolated context values are
        // escaped per the 'html' richness so a value like `<script>` lands
        // as `&lt;script&gt;` in the email and can't inject markup.
        $body = $notification->render((string) ($email['body'] ?? $type->body), 'html');

        $templateName = (string) ($email['template'] ?? '');

        $message = (new MailMessage)->subject($subject);

        // Brand fields (logo, logoDark, brand name, brandUrl) for the mail
        // theme's header / footer. Resolved via loose method conventions so
        // hosts get branded mails for free if their tenant model exposes
        // the expected accessors. Only non-empty values land in viewData;
        // missing keys let the mail theme apply its own fallbacks.
        $viewData = array_merge([
            'subject' => $subject,
            'content' => $body,
            'recipient' => $notifiable,
            'type' => $type,
        ], $this->resolveBrand($notifiable));

        // Always view-mode rendering — `resolveTemplateView()` guarantees
        // a registered template name. The historical `->line($body)`
        // fallback escaped HTML, which silently mangled rich-text bodies
        // when a template was missing or misspelled.
        $message->view($this->resolveTemplateView($templateName), $viewData);

        // Mail collapses multiple actions to its primary one — Laravel's
        // MailMessage convention is a single CTA.
        $actions = $notification->buildActions($type);

        if ($actions === [] && $type->actionResource !== null) {
            if ($url = $notification->buildLegacyActionUrl($type)) {
                $message->action($notification->resolveActionLabel($type), $url);
            }
        } elseif ($actions !== []) {
            $first = $actions[0];
            $url = $first->getUrl();

            if ($url) {
                $message->action(
                    $first->getLabel() ?? $notification->resolveActionLabel($type),
                    $url,
                );
            }
        }

        return $message;
    }

    /**
     * Resolve a template name to its Blade view path.
     *
     *   - Registry empty              → RuntimeException (fail loud — every
     *                                   email needs a template, and the
     *                                   package ships one. An empty registry
     *                                   is almost certainly a host
     *                                   misconfiguration, not a feature use.)
     *   - Empty name (no preference)  → first registered template (default)
     *   - Name found in the registry  → that template
     *   - Name NOT found              → log a warning, fall back to first
     *                                   registered template
     *
     * The not-found-name path used to return null and fall back to a
     * line-style mail render that escaped HTML — silently mangling rich-
     * text bodies. The new behaviour logs the typo (so QA can spot it in
     * the application logs) but still delivers a well-formed email using
     * the first registered template as the safety net. This matches the
     * empty-name path, which is the closest semantic neighbour.
     */
    protected function resolveTemplateView(string $name): string
    {
        $templates = config('notifications-max.email_templates', []);

        if (! is_array($templates) || $templates === []) {
            throw new RuntimeException(
                'notifications-max: at least one email template must be registered '
                ."in config('notifications-max.email_templates'). The shipped default is "
                .'"default" => "filament-notifications-max::mail.default".'
            );
        }

        if ($name === '') {
            return (string) reset($templates);
        }

        if (isset($templates[$name])) {
            return (string) $templates[$name];
        }

        Log::warning(
            'notifications-max: email template "'.$name.'" is not registered; '
            .'falling back to the first registered template. Check the type\'s '
            ."content.email.template value against config('notifications-max.email_templates').",
            [
                'requested' => $name,
                'available' => array_keys($templates),
            ],
        );

        return (string) reset($templates);
    }

    /**
     * Resolve the brand bag (logo, logoDark, brand name, brandUrl) for
     * the recipient. Returns whatever fields the host has populated;
     * absent fields fall through to the mail theme's own defaults.
     *
     * Convention probing — no contracts to implement, no service bindings.
     * Mirrors Filament's `HasAvatar` loose method-on-model pattern:
     *
     *   - $tenant->getLogoUrl()      → 'logo'
     *   - $tenant->getLogoDarkUrl()  → 'logoDark'
     *   - $tenant->name (attribute)  → 'brand'
     *   - $tenant->getBrandUrl()     → 'brandUrl'
     *
     * Multi-tenant: reads from `$notifiable->tenant` (Filament's
     * belongsTo convention). Single-tenant or no tenant relation: falls
     * back to Filament's currently-configured panel brand (logo + name).
     *
     * @return array<string, string>
     */
    protected function resolveBrand(object $notifiable): array
    {
        $brand = [];

        $tenant = $notifiable->tenant ?? null;

        if (is_object($tenant)) {
            if (method_exists($tenant, 'getLogoUrl')) {
                if ($v = $this->stringOrNull($tenant->getLogoUrl())) {
                    $brand['logo'] = $v;
                }
            }

            if (method_exists($tenant, 'getLogoDarkUrl')) {
                if ($v = $this->stringOrNull($tenant->getLogoDarkUrl())) {
                    $brand['logoDark'] = $v;
                }
            }

            // Tenant name — almost every tenant model has a `name`
            // attribute. No method probing needed; if the attribute
            // is absent or empty, just drop the key.
            if ($v = $this->stringOrNull($tenant->name ?? null)) {
                $brand['brand'] = $v;
            }

            if (method_exists($tenant, 'getBrandUrl')) {
                if ($v = $this->stringOrNull($tenant->getBrandUrl())) {
                    $brand['brandUrl'] = $v;
                }
            }
        }

        // Filament panel fallbacks — only fill keys the tenant didn't
        // already provide. Panels with HTML/Blade brand logos are
        // skipped here (mail headers can't render a Blade view as
        // `<img src>`); only string URLs are honoured.
        try {
            if (! isset($brand['logo'])) {
                $logo = \Filament\Facades\Filament::getBrandLogo();
                if (is_string($logo) && $logo !== '') {
                    $brand['logo'] = $logo;
                }
            }

            if (! isset($brand['brand'])) {
                $name = \Filament\Facades\Filament::getBrandName();
                if (is_string($name) && $name !== '') {
                    $brand['brand'] = $name;
                }
            }
        } catch (\Throwable) {
            // Filament facade not bound (CLI tests / non-Filament hosts).
        }

        // Absolutize URL-shaped fields. Mail recipients open the email in
        // their own context (Gmail, Outlook, mobile app) — a root-relative
        // `/storage/x.png` resolves nowhere there. Anything already
        // absolute (https://…, data:, etc.) is left untouched.
        foreach (['logo', 'logoDark', 'brandUrl'] as $key) {
            if (isset($brand[$key])) {
                $brand[$key] = $this->absolutize($brand[$key]);
            }
        }

        return $brand;
    }

    /**
     * Narrow a mixed value to a non-empty string, returning null
     * otherwise. Used to filter zero-length brand values out of the
     * resolved bag so the mail theme's own fallbacks can run.
     */
    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Promote a root-relative URL (`/storage/x.png`) to an absolute one
     * by prepending `config('app.url')`. Anything that already has a
     * scheme, a `//` prefix, or doesn't start with `/` is passed through
     * unchanged — so data URIs, CDN URLs, and protocol-relative URLs
     * survive intact.
     */
    protected function absolutize(string $url): string
    {
        if (! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return $url;
        }

        $base = rtrim((string) config('app.url', ''), '/');

        return $base === '' ? $url : $base.$url;
    }
}
