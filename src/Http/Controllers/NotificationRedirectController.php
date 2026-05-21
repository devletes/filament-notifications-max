<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Http\Controllers;

use Devletes\NotificationsMax\Services\NotificationActionUrlResolver;
use Devletes\NotificationsMax\Support\NotificationActionAddress;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves a notification's action URL at click time and 302s the
 * recipient to the right panel.
 *
 * Multi-panel hosts can't bake a single URL at dispatch time without
 * pinning the recipient to whichever panel the dispatcher picked. This
 * endpoint replaces the baked URL with a redirect that runs the panel-
 * picking rule once the user actually clicks — using the panel they were
 * on at the moment of the click (via `?from=`) to keep them in context.
 *
 * Access model: the route is gated by Laravel's `auth` middleware (i.e.
 * the host's default guard). Within that, we further scope the lookup to
 * the authenticated notifiable — a stranger's id returns 404 rather than
 * a 403 so existence isn't leaked.
 *
 * Legacy fallback: rows written before this feature don't carry
 * `data.action`. The controller falls through to the baked
 * `data.action_url` / `data.url`, so existing inboxes keep working.
 */
class NotificationRedirectController
{
    public function __invoke(
        Request $request,
        NotificationActionUrlResolver $resolver,
        string $notification,
    ): RedirectResponse {
        $user = Auth::user();

        // 'auth' middleware should have already rejected unauthenticated
        // requests, but guard against misconfigured route stacks.
        if ($user === null) {
            throw new NotFoundHttpException();
        }

        // Scope by the relation so foreign notifiables can't read each
        // other's notifications via this endpoint. Method only exists when
        // the user model uses Laravel's Notifiable trait — every Filament
        // user model does, so the call is safe in practice.
        if (! method_exists($user, 'notifications')) {
            throw new NotFoundHttpException();
        }

        /** @var DatabaseNotification|null $row */
        $row = $user->notifications()->whereKey($notification)->first();

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $url = $this->resolveUrl($row, $resolver, $request, $user);

        if ($url === null) {
            // Last-ditch: nothing on the row points anywhere. Send the
            // user to the panel's home — better than a hard error on a
            // click that originated from a real notification.
            $url = config('app.url', '/');
        }

        return redirect()->away($url);
    }

    /**
     * Try the new `data.action` payload first; fall back to legacy fields
     * for rows written before this feature.
     */
    protected function resolveUrl(
        DatabaseNotification $row,
        NotificationActionUrlResolver $resolver,
        Request $request,
        mixed $user,
    ): ?string {
        $address = NotificationActionAddress::fromArray(
            is_array($row->data['action'] ?? null) ? $row->data['action'] : null,
        );

        if ($address !== null) {
            $fromPanelId = $this->resolveFromPanel($request);

            $resolved = $resolver->resolve($address, $user, $fromPanelId);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        // Legacy rows: the URL was baked at dispatch time.
        $baked = $row->data['url'] ?? $row->data['action_url'] ?? null;

        return is_string($baked) && $baked !== '' ? $baked : null;
    }

    /**
     * Determine which panel the recipient was on when they clicked.
     *
     * Sources, in order:
     *   1. `?from=panel_id` query string — what the bell / notification
     *      center can set explicitly when they know the current panel.
     *   2. Referer header — fallback for clicks that didn't go through
     *      our renderers (mailto-injected clicks back into the app, JS
     *      navigation that lost the query, etc.). Parsed against the
     *      registered Filament panels' paths.
     *   3. null — the resolver falls through to the address's preferred
     *      panel, which is the right behaviour for mail clicks (no
     *      Referer from a mail client back to the app).
     */
    protected function resolveFromPanel(Request $request): ?string
    {
        $from = $request->query('from');

        if (is_string($from) && $from !== '') {
            return $from;
        }

        return $this->panelFromReferer($request);
    }

    /**
     * Map the Referer header to a registered panel id by matching the
     * referer's path against each panel's path (longest first). Same-
     * origin only — a cross-origin Referer is ignored so an attacker
     * can't trick the controller into picking a panel by setting their
     * own page as the Referer.
     */
    protected function panelFromReferer(Request $request): ?string
    {
        $referer = $request->headers->get('referer');

        if (! is_string($referer) || $referer === '') {
            return null;
        }

        try {
            $panels = Filament::getPanels();
        } catch (\Throwable) {
            return null;
        }

        $panelPaths = [];

        foreach ($panels as $panel) {
            $panelPaths[$panel->getId()] = trim($panel->getPath() ?? '', '/');
        }

        return self::matchRefererToPanel($referer, $request->getHost(), $panelPaths);
    }

    /**
     * Pure helper: given a Referer URL, the current request host, and a
     * map of panel id → panel path, return the id of the panel whose
     * path most specifically matches the Referer, or null. Cross-origin
     * Referers (different host) are rejected outright as a security
     * guard against an attacker tricking the controller by setting their
     * own page as the Referer.
     *
     * Pulled out as a static so it can be unit-tested without spinning
     * up Filament's panel registry or an HTTP kernel.
     *
     * @param  array<string, string>  $panelPaths  panel id → trimmed path (no leading slash). '' is a root-mounted panel and matches anything.
     */
    public static function matchRefererToPanel(string $referer, string $currentHost, array $panelPaths): ?string
    {
        $refererHost = parse_url($referer, PHP_URL_HOST);

        if ($refererHost !== $currentHost) {
            return null;
        }

        $refererPath = parse_url($referer, PHP_URL_PATH) ?: '/';
        $refererPath = '/'.ltrim($refererPath, '/');

        // Longest path first so '/employee' wins over a root-mounted
        // panel whose empty path otherwise catches everything.
        $sorted = $panelPaths;
        uasort($sorted, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($sorted as $id => $path) {
            if ($path === '') {
                // Catch-all (e.g. admin mounted at /). Only fires when
                // nothing more specific matched, by virtue of sort order.
                return $id;
            }

            $prefix = '/'.$path;

            if ($refererPath === $prefix || str_starts_with($refererPath, $prefix.'/')) {
                return $id;
            }
        }

        return null;
    }
}
