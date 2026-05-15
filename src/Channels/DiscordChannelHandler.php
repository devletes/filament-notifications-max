<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Channels;

use Devletes\NotificationsMax\Contracts\ChannelHandler;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Services\NotificationContentResolver;

/**
 * Renders a Discord payload for `laravel-notification-channels/discord`.
 * The host must install that package separately:
 *
 *   composer require laravel-notification-channels/discord
 *
 * configure `DISCORD_TOKEN` in `.env`, and implement
 * `routeNotificationForDiscord()` on the User model to return either a
 * channel id (for channel posts) or a private DM channel id.
 *
 * Returns a plain string — `laravel-notification-channels/discord`'s
 * channel class accepts a string content directly; richer payloads
 * (embeds, components) are available via the package's DiscordMessage
 * builder, which a host can return from their own subclassed handler.
 *
 * Channel content shape (read from `notifications-max.channels.discord`):
 *
 *   'discord' => [
 *       'label' => 'Discord',
 *       'physical' => ['discord'],
 *       'content_fields' => ['body' => 'text'],
 *   ],
 */
class DiscordChannelHandler implements ChannelHandler
{
    public function __construct(
        protected NotificationContentResolver $contentResolver,
    ) {}

    public function send(object $notifiable, GenericNotification $notification): string
    {
        $type = $notification->resolveType();

        $content = $this->contentResolver->contentFor(
            $type->key,
            'discord',
            $notification->resolveTenantId(),
        );

        return $notification->render(
            (string) ($content['body'] ?? $type->body),
        );
    }
}
