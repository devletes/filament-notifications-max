<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Contracts;

/**
 * Optional capability for {@see ActionUrlBuilder}s that can produce a
 * tenant-aware absolute base URL (scheme://host, no path).
 *
 * The click-redirect hop (`notifications-max.go`) uses this to pin its URL to
 * the correct tenant host. That matters when links are generated off-request
 * — e.g. inside a queued mail job, where Laravel's `route()` has no incoming
 * host and falls back to the (tenant-less) APP_URL, producing a link the
 * recipient can't authenticate against under subdomain tenancy.
 *
 * Builders that don't implement this keep the default `route()`-host
 * behaviour, so it's safe to add to existing custom builders incrementally.
 */
interface ProvidesActionBaseUrl
{
    /**
     * Absolute `scheme://host` (no trailing slash, no path) for the tenant in
     * $context, or null when the default APP_URL host is correct or no tenant
     * is resolvable.
     *
     * @param  array<string, mixed>  $context
     */
    public function baseUrl(array $context = []): ?string;
}
