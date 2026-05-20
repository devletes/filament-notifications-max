<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Looks up a Slack workspace member by email and returns their Slack
 * user ID, used by the package's auto-resolve listener and the
 * `notifications-max:sync-slack-user-ids` backfill command.
 *
 * Wraps Slack's `users.lookupByEmail` endpoint:
 * https://api.slack.com/methods/users.lookupByEmail
 *
 * Requires the configured bot token to carry the `users:read.email`
 * scope. Without it Slack returns `missing_scope` and the resolver
 * surfaces a RuntimeException so misconfiguration shows up loudly
 * rather than silently leaving every user un-routable.
 *
 * Error handling rationale:
 *
 *   - `users_not_found`  → null. Expected — host has users who aren't
 *                          in this Slack workspace. The caller decides
 *                          what to do (auto-resolve listener just
 *                          skips; the sync command counts it under
 *                          "not found" in its summary).
 *
 *   - `invalid_auth` /   → RuntimeException. Config-level problem
 *     `not_authed` /       (wrong token / missing scope / disabled
 *     `account_inactive` / app). Should surface to operators, not
 *     `missing_scope`      hide behind a "no slack user found" row.
 *
 *   - HTTP failure        → RuntimeException via $response->throw().
 *                          Network / 5xx errors are bubbled up the
 *                          same way the caller can decide to retry.
 *
 *   - `ratelimited`       → RuntimeException so the caller throttles.
 *                          The sync command paces its calls already
 *                          (Tier 2 = 20/min), so this would only fire
 *                          if multiple workers piled on at once.
 */
class SlackUserIdResolver
{
    public function __construct(
        protected HttpFactory $http,
    ) {}

    /**
     * @return string|null  Slack user ID (e.g. "U0123456789") or null
     *                      when the email isn't a member of the
     *                      workspace.
     */
    public function resolve(string $email): ?string
    {
        $token = (string) config('services.slack.notifications.bot_user_oauth_token', '');

        if ($token === '') {
            throw new RuntimeException(
                'notifications-max: services.slack.notifications.bot_user_oauth_token '
                .'is not configured. Set SLACK_BOT_USER_OAUTH_TOKEN in .env to a bot '
                .'token with the `users:read.email` scope.'
            );
        }

        $response = $this->http
            ->asJson()
            ->withToken($token)
            ->get('https://slack.com/api/users.lookupByEmail', ['email' => $email])
            ->throw();

        $payload = (array) $response->json();

        if (($payload['ok'] ?? false) === true) {
            $id = $payload['user']['id'] ?? null;

            return is_string($id) && $id !== '' ? $id : null;
        }

        $error = (string) ($payload['error'] ?? 'unknown_error');

        if ($error === 'users_not_found') {
            return null;
        }

        Log::warning('notifications-max: Slack users.lookupByEmail failed', [
            'email' => $email,
            'error' => $error,
        ]);

        throw new RuntimeException(
            "notifications-max: Slack users.lookupByEmail returned error [{$error}]. "
            .'Check that SLACK_BOT_USER_OAUTH_TOKEN has the `users:read.email` scope '
            .'and that the bot is installed in the target workspace.'
        );
    }
}
