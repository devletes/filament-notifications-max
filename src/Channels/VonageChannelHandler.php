<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Channels;

use Devletes\NotificationsMax\Contracts\ChannelHandler;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use Illuminate\Notifications\Messages\VonageMessage;

/**
 * Renders an SMS payload for Laravel's official Vonage notification channel
 * (`laravel/vonage-notification-channel`). The host must install that
 * package separately:
 *
 *   composer require laravel/vonage-notification-channel
 *
 * configure `VONAGE_KEY`, `VONAGE_SECRET`, `VONAGE_SMS_FROM` in `.env`, and
 * implement `routeNotificationForVonage()` on their User model.
 *
 * Channel content shape (read from `notifications-max.channels.sms`):
 *
 *   'sms' => [
 *       'label' => 'SMS',
 *       'physical' => ['vonage'],
 *       'content_fields' => ['body' => 'text'],
 *   ],
 *
 * Use either {@see TwilioChannelHandler} or this one — not both — depending
 * on the SMS provider the host chose. The `sms` logical channel maps to one
 * physical channel.
 */
class VonageChannelHandler implements ChannelHandler
{
    public function __construct(
        protected NotificationContentResolver $contentResolver,
    ) {}

    public function send(object $notifiable, GenericNotification $notification): VonageMessage
    {
        $type = $notification->resolveType();

        $content = $this->contentResolver->contentFor(
            $type->key,
            'sms',
            $notification->resolveTenantId(),
        );

        $body = $notification->render(
            (string) ($content['body'] ?? $type->body),
        );

        return (new VonageMessage)->content($body);
    }
}
