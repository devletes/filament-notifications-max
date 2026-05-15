<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Channels;

use Devletes\NotificationsMax\Contracts\ChannelHandler;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use Illuminate\Notifications\Messages\MailMessage;

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

        $subject = $notification->render((string) ($email['subject'] ?? $type->title));
        $body = $notification->render((string) ($email['body'] ?? $type->body));
        $templateName = (string) ($email['template'] ?? '');

        $message = (new MailMessage)->subject($subject);

        $templateView = $this->resolveTemplateView($templateName);

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
     *   - No templates configured     → null (caller renders a line-style mail)
     *   - Empty name (no preference)  → first registered template (default)
     *   - Name found in the registry  → that template
     *   - Name NOT found              → null (fall back to line-style mail)
     *
     * The not-found-name case returns null on purpose. Silently falling
     * back to the first template would mask misspelled names: emails
     * render fine but with the wrong layout. A null return surfaces the
     * typo as a less-styled email in QA.
     */
    protected function resolveTemplateView(string $name): ?string
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
}
