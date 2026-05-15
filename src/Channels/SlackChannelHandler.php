<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Channels;

use Devletes\NotificationsMax\Contracts\ChannelHandler;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Renders a Slack payload for Laravel's first-party slack channel
 * (`laravel/slack-notification-channel`). The host must install that
 * package separately:
 *
 *   composer require laravel/slack-notification-channel
 *
 * and either set `SLACK_BOT_USER_OAUTH_TOKEN` for bot-token routing or
 * configure per-user routing via `routeNotificationForSlack()`.
 *
 * Caveat — Slack is most naturally a team-channel destination (post to
 * `#engineering`), not per-user. If the host's `routeNotificationForSlack()`
 * returns a workspace channel id, EVERY user's "slack" notification lands
 * in that one channel, which can be surprising. Set up per-user Slack DMs
 * via webhook urls if you want true per-user delivery, or treat the slack
 * channel as a team broadcast feed rather than a personal one.
 *
 * Channel content shape (read from `notifications-max.channels.slack`):
 *
 *   'slack' => [
 *       'label' => 'Slack',
 *       'physical' => ['slack'],
 *       'content_fields' => ['body' => 'text'],
 *   ],
 */
class SlackChannelHandler implements ChannelHandler
{
    public function __construct(
        protected NotificationContentResolver $contentResolver,
    ) {}

    public function send(object $notifiable, GenericNotification $notification): SlackMessage
    {
        $type = $notification->resolveType();

        $content = $this->contentResolver->contentFor(
            $type->key,
            'slack',
            $notification->resolveTenantId(),
        );

        $body = $notification->render(
            (string) ($content['body'] ?? $type->body),
        );

        return (new SlackMessage)->text($body);
    }
}
