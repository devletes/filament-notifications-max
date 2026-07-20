<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Defaults;

use Devletes\NotificationsMax\Contracts\ActionUrlBuilder;
use Filament\Facades\Filament;

/**
 * Path-based URL builder for apps that are not domain-multi-tenant.
 *
 * Produces URLs like:  https://app.example.com/admin/requests/42
 *                      https://app.example.com/employee/tasks/17
 *
 * With `$context['table_action']` set, the record segment moves into
 * Filament's index-page query params instead:
 *
 *   https://app.example.com/admin/requests?tableAction=view&tableActionRecord=42
 */
class PathActionUrlBuilder implements ActionUrlBuilder
{
    public function build(
        string $panelId,
        string $resourceSlug,
        int|string $recordId,
        array $context = [],
    ): string {
        // Ask Filament for the panel's URL prefix if the panel is registered;
        // fall back to $panelId for single-panel apps.
        $panel = Filament::getPanel($panelId, isStrict: false);
        $panelPath = $panel?->getPath() ?? $panelId;
        $panelPath = trim($panelPath, '/');

        $base = rtrim(config('app.url') ?: '', '/');

        $tableAction = $context['table_action'] ?? null;

        if (is_string($tableAction) && $tableAction !== '') {
            // Query-string form: target the resource INDEX and let
            // Filament auto-mount the named table action for the record
            // (`?tableAction=` / `?tableActionRecord=` — the params its
            // list components bind via `#[Url]`). This is how records on
            // resources WITHOUT a detail page (modal-on-list) stay
            // linkable; the path form below would 404 for them.
            $path = ltrim(implode('/', array_filter([$panelPath, $resourceSlug])), '/');
            $query = http_build_query([
                'tableAction' => $tableAction,
                'tableActionRecord' => (string) $recordId,
            ]);
            $url = $path === '' ? $base : "{$base}/{$path}";

            return "{$url}?{$query}";
        }

        $path = ltrim(
            implode('/', array_filter([$panelPath, $resourceSlug, (string) $recordId])),
            '/',
        );

        return $path === '' ? $base : "{$base}/{$path}";
    }
}
