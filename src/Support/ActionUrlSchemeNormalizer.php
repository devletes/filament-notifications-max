<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Http\Request;

/**
 * Upgrades `http://` notification-action URLs to `https://` when the current
 * request is itself secure and the URL points at this application.
 *
 * Action URLs are baked into the notification row at creation time — often
 * inside a queue worker, where URL generation falls back to `APP_URL`. A host
 * that serves over https but still carries `APP_URL=http://…` (or flipped to
 * https after rows were written) ends up with `http://` links persisted
 * forever in `data.actions[].url` / `data.url`. On a full-page click that
 * mistake self-heals silently: the browser follows the http link and the
 * server's http→https redirect fixes it up. Under Filament's SPA mode,
 * however, notification links carry `wire:navigate`, and Livewire fetches the
 * target itself — an https page fetching an http URL is mixed content, which
 * browsers hard-block before any request leaves. The click does nothing and
 * no error surfaces. Because legacy rows keep their baked scheme forever,
 * healing has to happen where the stored URL is USED: at render time (the
 * bell dropdown's `database-notifications` view override) and at redirect
 * time ({@see \Devletes\NotificationsMax\Http\Controllers\NotificationRedirectController}),
 * not just at creation.
 *
 * The upgrade fires only when ALL of the following hold:
 *
 *   - the current request is secure (`$request->isSecure()`) — an http
 *     deployment keeps its URLs untouched;
 *   - the URL's scheme is exactly `http` — https is never downgraded, and
 *     relative / protocol-relative URLs pass through (they are already
 *     scheme-relative and fine);
 *   - the URL's host is one of ours: equal to the current request's host,
 *     equal to the `app.url` host, or a subdomain of the `app.url` host
 *     (tenant subdomains). Genuinely external links are never rewritten.
 *
 * Without a request (`null` — e.g. CLI code paths that have no client
 * request to mirror) URLs pass through unchanged. Note that `isSecure()`
 * relies on the host app's trusted-proxy configuration when TLS terminates
 * upstream — same contract as every other https-detection in Laravel.
 */
final class ActionUrlSchemeNormalizer
{
    public static function normalize(string $url, ?Request $request): string
    {
        if ($request === null || ! $request->isSecure()) {
            return $url;
        }

        // Scheme must be exactly http. This single check also filters out
        // https URLs, relative paths, protocol-relative URLs, and
        // non-web schemes (mailto:, tel:, …).
        if (stripos($url, 'http://') !== 0) {
            return $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return $url;
        }

        if (! self::isOurHost(strtolower($host), $request)) {
            return $url;
        }

        // Swap only the scheme; path, query, port, and userinfo survive.
        return 'https://'.substr($url, strlen('http://'));
    }

    /**
     * Walk a hydrated Filament notification (as rebuilt from a stored row by
     * `Notification::fromDatabase()`) and normalize every action URL in
     * place. Returns the same instance for expression-position chaining in
     * blade. ActionGroups expose their leaves — nested groups included — via
     * `getFlatActions()`, so one level of unwrapping covers the whole tree.
     */
    public static function normalizeNotification(
        FilamentNotification $notification,
        ?Request $request,
    ): FilamentNotification {
        foreach ($notification->getActions() as $action) {
            if ($action instanceof ActionGroup) {
                foreach ($action->getFlatActions() as $grouped) {
                    self::normalizeActionUrl($grouped, $request);
                }

                continue;
            }

            self::normalizeActionUrl($action, $request);
        }

        return $notification;
    }

    protected static function normalizeActionUrl(Action $action, ?Request $request): void
    {
        $url = $action->getUrl();

        if (! is_string($url) || $url === '') {
            return;
        }

        $normalized = self::normalize($url, $request);

        if ($normalized !== $url) {
            // `url()` leaves the open-in-new-tab flag alone when its second
            // argument is omitted, so this rewrites only the target.
            $action->url($normalized);
        }
    }

    /**
     * "Ours" = the current request's host, the `app.url` host, or a
     * subdomain of the `app.url` host. Comparisons are case-insensitive
     * and ignore ports (hosts never carry them; `Request::getHost()` and
     * `parse_url(…, PHP_URL_HOST)` both strip the port).
     */
    protected static function isOurHost(string $host, Request $request): bool
    {
        if ($host === strtolower($request->getHost())) {
            return true;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($appHost) || $appHost === '') {
            return false;
        }

        $appHost = strtolower($appHost);

        return $host === $appHost || str_ends_with($host, '.'.$appHost);
    }
}
