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
 * ## Markdown dialect
 *
 * Slack renders messages with its own `mrkdwn` flavour — not standard
 * markdown:
 *
 *   *bold*           _italic_           ~strike~
 *   `inline code`    ```multiline```    <url|label>
 *
 * Templates configured for the slack channel are authored as mrkdwn
 * (the package's content resolver returns them verbatim). Interpolated
 * `{token}` values from the dispatch context are backslash-escaped by
 * {@see GenericNotification::render()} so a user-supplied value
 * containing `*foo*` doesn't accidentally trigger bold formatting after
 * substitution. Templates remain trusted.
 *
 * Channel content shape (read from `notifications-max.channels.slack`):
 *
 *   'slack' => [
 *       'label' => 'Slack',
 *       'physical' => ['slack'],
 *       'richness' => 'markdown',
 *       'content_fields' => ['body' => 'markdown'],
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

        // Slack's mrkdwn dialect — render with markdown richness so
        // interpolated values get backslash-escaped against the flavour's
        // formatting chars (`*_~` + backtick + `&<>` HTML entities).
        $body = $notification->render(
            (string) ($content['body'] ?? $type->body),
            $this->contentResolver->richnessFor('slack'),
        );

        return (new SlackMessage)->text($body);
    }
}
