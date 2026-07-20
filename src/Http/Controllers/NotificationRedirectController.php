<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Http\Controllers;

use Devletes\NotificationsMax\Services\NotificationActionUrlResolver;
use Devletes\NotificationsMax\Support\ActionUrlSchemeNormalizer;
use Devletes\NotificationsMax\Support\NotificationActionAddress;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves a notification's action URL at click time and 302s to the right
 * panel. Stranger's id returns 404 (not 403) to avoid leaking existence.
 * Falls back to baked `data.url` / `data.action_url` for legacy rows.
 *
 * Clicking the link is treated as acknowledgement: the row is marked read
 * before the redirect (so a Slack/email click clears the in-app bell), gated
 * by `notifications-max.redirect_route.mark_read`. `markAsRead()` is a no-op
 * on an already-read row, so re-clicks don't churn the timestamp.
 *
 * The resolved target's scheme is normalized against the current request
 * ({@see ActionUrlSchemeNormalizer}) so legacy rows whose baked URL inherited
 * an `http://` APP_URL don't bounce an https visitor back onto http.
 */
class NotificationRedirectController
{
    public function __invoke(
        Request $request,
        NotificationActionUrlResolver $resolver,
        string $notification,
    ): RedirectResponse {
        $user = Auth::user();

        if ($user === null || ! method_exists($user, 'notifications')) {
            throw new NotFoundHttpException();
        }

        /** @var DatabaseNotification|null $row */
        $row = $user->notifications()->whereKey($notification)->first();

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        if (config('notifications-max.redirect_route.mark_read', true)) {
            $row->markAsRead();
        }

        $url = $this->resolveUrl($row, $resolver, $request, $user)
            ?? config('app.url', '/');

        return redirect()->away(ActionUrlSchemeNormalizer::normalize($url, $request));
    }

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
            $resolved = $resolver->resolve($address, $user, $this->resolveFromPanel($request));

            if ($resolved !== null) {
                return $resolved;
            }
        }

        $baked = $row->data['url'] ?? $row->data['action_url'] ?? null;

        return is_string($baked) && $baked !== '' ? $baked : null;
    }

    protected function resolveFromPanel(Request $request): ?string
    {
        $from = $request->query('from');

        if (is_string($from) && $from !== '') {
            return $from;
        }

        return $this->panelFromReferer($request);
    }

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
     * Map a Referer URL to a panel id. Same-origin only — a cross-origin
     * Referer is ignored as a security guard against attacker-set referers.
     *
     * @param  array<string, string>  $panelPaths  panel id → trimmed path (no leading slash). '' acts as a catch-all.
     */
    public static function matchRefererToPanel(string $referer, string $currentHost, array $panelPaths): ?string
    {
        $refererHost = parse_url($referer, PHP_URL_HOST);

        if ($refererHost !== $currentHost) {
            return null;
        }

        $refererPath = parse_url($referer, PHP_URL_PATH) ?: '/';
        $refererPath = '/'.ltrim($refererPath, '/');

        // Longest path first so '/employee' wins over a root-mounted panel.
        $sorted = $panelPaths;
        uasort($sorted, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($sorted as $id => $path) {
            if ($path === '') {
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
