<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Channels;

use Devletes\NotificationsMax\Contracts\ChannelHandler;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use NotificationChannels\Twilio\TwilioSmsMessage;

/**
 * Renders an SMS payload for `laravel-notification-channels/twilio`. The
 * host must install that package separately:
 *
 *   composer require laravel-notification-channels/twilio
 *
 * and configure `TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM` in `.env`, and
 * implement `routeNotificationForTwilio()` on their User model (or use the
 * trait the Twilio package ships). The handler itself only renders the
 * message body; the third-party channel class handles the API call and
 * delivery.
 *
 * Channel content shape (read from `notifications-max.channels.sms`):
 *
 *   'sms' => [
 *       'label' => 'SMS',
 *       'physical' => ['twilio'],
 *       'content_fields' => ['body' => 'text'],
 *   ],
 */
class TwilioChannelHandler implements ChannelHandler
{
    public function __construct(
        protected NotificationContentResolver $contentResolver,
    ) {}

    public function send(object $notifiable, GenericNotification $notification): TwilioSmsMessage
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

        return TwilioSmsMessage::create()->content($body);
    }
}
