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

        $path = ltrim(
            implode('/', array_filter([$panelPath, $resourceSlug, (string) $recordId])),
            '/',
        );

        return $path === '' ? $base : "{$base}/{$path}";
    }
}
