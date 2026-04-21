<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Contracts;

/**
 * Builds absolute URLs for notification action buttons.
 *
 * A notification's "View" action must produce a URL that routes to the
 * correct panel and resource regardless of which panel the user's bell
 * dropdown is open in. Different apps use different URL schemes (subdomain
 * tenancy, path tenancy, single-tenant), so URL construction is pluggable.
 *
 * The package ships two defaults:
 *   - SubdomainActionUrlBuilder: `{scheme}://{tenant_slug}.{app_domain}{panel_path}/{resource}/{id}`
 *   - PathActionUrlBuilder:      `{scheme}://{app_domain}{panel_path}/{resource}/{id}`
 */
interface ActionUrlBuilder
{
    /**
     * @param  string           $panelId      Filament panel id (e.g. "admin", "employee")
     * @param  string           $resourceSlug Panel-relative resource slug (e.g. "requests")
     * @param  int|string       $recordId     The record to link to
     * @param  array<string, mixed> $context  Additional data passed from the notification (tenant_slug, etc.)
     */
    public function build(
        string $panelId,
        string $resourceSlug,
        int|string $recordId,
        array $context = [],
    ): string;
}
