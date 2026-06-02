<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Defaults;

use Devletes\NotificationsMax\Contracts\ActionUrlBuilder;
use Devletes\NotificationsMax\Contracts\ProvidesActionBaseUrl;
use Filament\Facades\Filament;

/**
 * Subdomain-based URL builder for apps that use Filament's tenant-per-subdomain
 * pattern (e.g. `{tenant:slug}.example.com`).
 *
 * Produces URLs like:  https://acme.example.com/admin/requests/42
 *                      https://acme.example.com/employee/tasks/17
 *
 * Requires `$context['tenant_slug']` to be set at dispatch time. Falls back
 * to the path builder output if no tenant_slug is available (e.g. a console
 * notification with no tenant context).
 */
class SubdomainActionUrlBuilder implements ActionUrlBuilder, ProvidesActionBaseUrl
{
    public function __construct(
        private readonly PathActionUrlBuilder $pathFallback,
    ) {}

    public function build(
        string $panelId,
        string $resourceSlug,
        int|string $recordId,
        array $context = [],
    ): string {
        $base = $this->baseUrl($context);

        if ($base === null) {
            // Without a tenant we can't construct a subdomain — fall back
            // to the path builder so the URL is still usable.
            return $this->pathFallback->build($panelId, $resourceSlug, $recordId, $context);
        }

        // Fall back to the panel id as the path segment when no panel is
        // registered with that id (typical in tests, and harmless in
        // production where the id is conventionally the same as the path).
        // Matches {@see PathActionUrlBuilder}.
        $panel = Filament::getPanel($panelId, isStrict: false);
        $panelPath = trim($panel?->getPath() ?? $panelId, '/');

        $path = ltrim(
            implode('/', array_filter([$panelPath, $resourceSlug, (string) $recordId])),
            '/',
        );

        return $path === '' ? $base : "{$base}/{$path}";
    }

    public function baseUrl(array $context = []): ?string
    {
        $tenantSlug = $context['tenant_slug'] ?? null;

        if (! is_string($tenantSlug) || $tenantSlug === '') {
            return null;
        }

        $appUrl = config('app.url') ?: 'http://localhost';
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';
        $domain = config('app.domain') ?: parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';

        return "{$scheme}://{$tenantSlug}.{$domain}";
    }
}
