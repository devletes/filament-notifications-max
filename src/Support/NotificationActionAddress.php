<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Support;

use InvalidArgumentException;

/**
 * Resource address carried on a notification, resolved to a URL at click time.
 *
 * Replaces the pattern of baking a single URL into `data.action_url` at
 * dispatch time. Instead, dispatch sites declare *where the underlying
 * record lives* (which resource, on which panel or panels), and the URL
 * is built when the recipient clicks — using the panel they were on at
 * the moment of the click. That keeps multi-panel users (admin-and-also-
 * employee in HRMS, for instance) in the panel they were working in.
 *
 * Persisted on the notification row as `data.action`:
 *
 *   [
 *     'resource'        => 'tasks',
 *     'record_id'       => 17,
 *     'panels'          => ['admin', 'employee'],
 *     'preferred_panel' => 'employee',
 *     'tenant_slug'     => 'acme',
 *   ]
 *
 * Read by {@see \Devletes\NotificationsMax\Services\NotificationActionUrlResolver}
 * and the redirect controller.
 */
final class NotificationActionAddress
{
    /**
     * @param  array<int, string>  $panels
     */
    public function __construct(
        public readonly string $resource,
        public readonly int|string $recordId,
        public readonly array $panels,
        public readonly ?string $preferredPanel = null,
        public readonly ?string $tenantSlug = null,
    ) {
        if ($resource === '') {
            throw new InvalidArgumentException('NotificationActionAddress requires a non-empty resource slug.');
        }

        if ($recordId === '' || $recordId === 0) {
            // 0 is a valid record id in theory but signals an unset value in
            // practice — the dispatch site forgot to wire the foreign key.
            // Reject early so the rendered notification doesn't link to
            // /resource/0.
            throw new InvalidArgumentException('NotificationActionAddress requires a non-empty record id.');
        }
    }

    /**
     * Hydrate from the array stored at `data.action` (or the dispatch
     * context's `action` key). Returns null when the payload is missing
     * required fields — callers tolerate a null address by falling back
     * to a baked `data.url` / `data.action_url` for legacy rows.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromArray(?array $payload): ?self
    {
        if ($payload === null) {
            return null;
        }

        $resource = $payload['resource'] ?? null;
        $recordId = $payload['record_id'] ?? null;

        if (! is_string($resource) || $resource === '') {
            return null;
        }

        if ($recordId === null || $recordId === '' || $recordId === 0) {
            return null;
        }

        if (! is_int($recordId) && ! is_string($recordId)) {
            return null;
        }

        $panelsRaw = $payload['panels'] ?? [];
        $panels = is_array($panelsRaw)
            ? array_values(array_filter(
                array_map(fn ($p) => is_string($p) ? trim($p) : '', $panelsRaw),
                fn (string $p) => $p !== '',
            ))
            : [];

        $preferred = $payload['preferred_panel'] ?? null;
        if (! is_string($preferred) || $preferred === '') {
            $preferred = null;
        }

        $tenantSlug = $payload['tenant_slug'] ?? null;
        if (! is_string($tenantSlug) || $tenantSlug === '') {
            $tenantSlug = null;
        }

        return new self(
            resource: $resource,
            recordId: $recordId,
            panels: $panels,
            preferredPanel: $preferred,
            tenantSlug: $tenantSlug,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resource' => $this->resource,
            'record_id' => $this->recordId,
            'panels' => $this->panels,
            'preferred_panel' => $this->preferredPanel,
            'tenant_slug' => $this->tenantSlug,
        ];
    }
}
