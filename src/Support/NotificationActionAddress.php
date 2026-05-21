<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Support;

use InvalidArgumentException;

/**
 * Resource address carried on a notification, resolved to a URL at click time.
 *
 * Persisted on the row as `data.action`; read by the redirect controller.
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

        // 0 is technically valid but signals an unset foreign key in
        // practice — fail fast instead of rendering /resource/0.
        if ($recordId === '' || $recordId === 0) {
            throw new InvalidArgumentException('NotificationActionAddress requires a non-empty record id.');
        }
    }

    /**
     * Hydrate from `data.action`. Returns null on malformed payloads so
     * the renderer can fall back to legacy `data.url` / `data.action_url`.
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
