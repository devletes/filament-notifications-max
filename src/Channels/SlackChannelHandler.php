<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Channels;

use Devletes\NotificationsMax\Contracts\ChannelHandler;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Devletes\NotificationsMax\Registry\NotificationType;
use Devletes\NotificationsMax\Services\NotificationContentResolver;
use Illuminate\Notifications\Slack\BlockKit\Elements\ButtonElement;
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
 *       'content_fields' => ['title' => 'string', 'body' => 'markdown'],
 *   ],
 *
 * ## Render formats
 *
 * `notifications-max.channels.slack.format` selects how the message is laid
 * out (it sits with the channel's other render settings, next to `richness`):
 *
 *   'link' (default) — title as a bold mrkdwn headline, body beneath, and
 *       (when the type has an actionable target) a trailing `<url|label>`
 *       "View" link. A single plain-text message; works everywhere with no
 *       Slack-app setup.
 *
 *   'blocks' — a Block Kit layout: a leading divider, a header block (the
 *       title, plain_text), then a section block (the body, mrkdwn) carrying
 *       a primary "View" button as its accessory. The `text()` is still set
 *       as the notification preview / fallback for surfaces that can't render
 *       blocks. The button is a URL button — clicking it opens the url AND
 *       fires a `block_actions` interaction payload, so for glitch-free
 *       behaviour the Slack app should have Interactivity configured.
 *
 * Either way, installs predating the `title` content field still get a
 * headline — it falls back to the type's top-level `title`.
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

        // Read alongside the channel's other render settings (richness,
        // content_fields) from `notifications-max.channels.slack` — same place
        // `richnessFor()` reads from, so all Slack rendering config lives
        // together. Unknown / absent value falls back to the plain link format.
        $format = $this->contentResolver->channelDefinition('slack')['format'] ?? 'link';

        return match ($format) {
            'blocks' => $this->renderBlocks($notification, $type, $content),
            default => $this->renderText($notification, $type, $content),
        };
    }

    /**
     * 'link' format (default): a single mrkdwn text message — bold title,
     * body beneath, and a trailing `<url|label>` "View" link. No Slack-app
     * setup required; renders the same everywhere.
     *
     * @param  array<string, scalar|null>  $content
     */
    protected function renderText(GenericNotification $notification, NotificationType $type, array $content): SlackMessage
    {
        $richness = $this->contentResolver->richnessFor('slack');

        // Slack's mrkdwn dialect — render title and body with markdown
        // richness so interpolated context values get escaped against the
        // flavour's formatting chars (`*_~` + backtick + `&<>` entities).
        // The body on its own is often ambiguous ("All approvals are in."
        // — for what?), so the title leads as a bold headline above it,
        // mirroring how the push/email channels frame the same content.
        $title = $notification->render((string) ($content['title'] ?? $type->title), $richness);
        $body = $notification->render((string) ($content['body'] ?? $type->body), $richness);

        $lines = [];

        if ($title !== '') {
            $lines[] = "*{$title}*";
        }

        if ($body !== '') {
            $lines[] = $body;
        }

        $text = implode("\n", $lines);

        // Append the primary action as an mrkdwn link (`<url|label>`) so the
        // recipient gets a "View" affordance — without it the message is
        // text-only and the user has to hunt down the record.
        if ($link = $this->actionLink($notification, $type)) {
            $text .= "\n\n".$link;
        }

        return (new SlackMessage)->text($text);
    }

    /**
     * 'blocks' format: a Block Kit layout — leading divider, header (title,
     * plain_text), then a section (body, mrkdwn) carrying a primary "View"
     * button as its accessory. `text()` is set as the notification preview /
     * fallback for surfaces that can't render blocks.
     *
     * @param  array<string, scalar|null>  $content
     */
    protected function renderBlocks(GenericNotification $notification, NotificationType $type, array $content): SlackMessage
    {
        // Header is a plain_text block — Slack doesn't parse mrkdwn there — so
        // render the title with the default plain richness (no escaping).
        $title = $notification->render((string) ($content['title'] ?? $type->title));

        // Section text is mrkdwn — render with the channel's markdown richness
        // so interpolated values are escaped against the flavour.
        $body = $notification->render(
            (string) ($content['body'] ?? $type->body),
            $this->contentResolver->richnessFor('slack'),
        );

        // `text` is the notification preview + the fallback for any surface
        // that can't render blocks. Never empty — Slack requires text or a
        // block, and an all-blank message would be a degenerate type anyway.
        $fallback = implode("\n", array_filter([$title, $body]));

        $message = (new SlackMessage)->text($fallback !== '' ? $fallback : ' ');

        // Divider leads each notification, giving a clean separator between
        // consecutive messages in the DM/channel.
        $message->dividerBlock();

        if ($title !== '') {
            $message->headerBlock($title, fn ($text) => $text->emoji());
        }

        $action = $this->primaryAction($notification, $type);

        if ($body !== '') {
            $message->sectionBlock(function ($block) use ($body, $action): void {
                $block->text($body)->markdown();

                if ($action !== null) {
                    $button = new ButtonElement($action['label']);
                    $button->url($action['url'])->primary();
                    $block->accessory($button);
                }
            });
        } elseif ($action !== null) {
            // No body to anchor the accessory — emit the button on its own row.
            $message->actionsBlock(fn ($block) => $block->button($action['label'])->url($action['url'])->primary());
        }

        return $message;
    }

    /**
     * The notification's primary action as a `['url' => ..., 'label' => ...]`
     * pair, or null when the type declares no actionable target. The single
     * source of truth for both the link ({@see actionLink()}) and block-button
     * renderings; mirrors how the mail channel collapses
     * {@see GenericNotification::buildActions()} to one CTA.
     *
     * @return array{url: string, label: string}|null
     */
    protected function primaryAction(GenericNotification $notification, NotificationType $type): ?array
    {
        $actions = $notification->buildActions($type);

        if ($actions === []) {
            return null;
        }

        $url = $actions[0]->getUrl();

        if (! is_string($url) || $url === '') {
            return null;
        }

        $label = $actions[0]->getLabel();

        if (! is_string($label) || $label === '') {
            $label = $notification->resolveActionLabel($type);
        }

        return ['url' => $url, 'label' => $label];
    }

    /**
     * The notification's primary action as an mrkdwn link (`<url|label>`),
     * or null when the type declares no actionable target.
     */
    protected function actionLink(GenericNotification $notification, NotificationType $type): ?string
    {
        $action = $this->primaryAction($notification, $type);

        if ($action === null) {
            return null;
        }

        // Sanitise the label against the `<url|label>` delimiters so a stray
        // `|`, `<`, or `>` can't break out of the link syntax. The URL is
        // package-generated and trusted, so it's emitted raw.
        $label = strtr($action['label'], ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '|' => ' ']);

        return "<{$action['url']}|{$label}>";
    }
}
