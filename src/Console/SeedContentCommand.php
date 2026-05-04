<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Console;

use Devletes\NotificationsMax\Models\NotificationTypeOverride;
use Devletes\NotificationsMax\Registry\NotificationType;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Populate the notification_type_overrides table from current config so
 * admins inherit the package's defaults the moment they switch to
 * database content mode.
 *
 * Usage:
 *
 *   php artisan notifications-max:seed-content                  # all tenants (NULL too)
 *   php artisan notifications-max:seed-content --tenant=1       # one tenant
 *   php artisan notifications-max:seed-content --tenant=null    # single-tenant install (NULL row)
 *   php artisan notifications-max:seed-content --fresh          # wipe existing rows for the targeted tenants first
 *
 * Resolution post-seed: rows match config defaults exactly, so admin
 * edits diverge from config explicitly. The package's resolver (DB mode)
 * treats stored values as authoritative — what's in the row is what fires.
 *
 * Tenants the command considers:
 *   - When --tenant=NUMERIC, just that one (creates / updates the row)
 *   - When --tenant=null, just the NULL-tenant row (single-tenant installs)
 *   - When --tenant is omitted, every distinct tenant_id already represented
 *     in the table plus NULL. Hosts that need broader fan-out (every tenant
 *     in their tenants table) wire their own loop over their tenant model
 *     and call the command per id, since the package can't see the host's
 *     Tenant table.
 */
class SeedContentCommand extends Command
{
    protected $signature = 'notifications-max:seed-content
                            {--tenant= : Tenant id (numeric) or "null" for single-tenant; omit for all known tenants}
                            {--fresh : Drop existing rows for the targeted tenants before seeding}';

    protected $description = 'Populate notification_type_overrides from config defaults so admins can edit-in-place.';

    public function handle(NotificationTypeRegistry $registry): int
    {
        if (! Schema::hasTable('notification_type_overrides')) {
            $this->error('Table `notification_type_overrides` does not exist. Run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $tenantIds = $this->resolveTenantIds();
        $types = $registry->all();

        if ($types === []) {
            $this->warn('No notification types registered — nothing to seed.');

            return self::SUCCESS;
        }

        $totalSeeded = 0;
        $totalSkipped = 0;

        foreach ($tenantIds as $tenantId) {
            $label = $tenantId === null ? 'NULL (single-tenant)' : (string) $tenantId;

            if ($this->option('fresh')) {
                $deleted = NotificationTypeOverride::query()
                    ->where('tenant_id', $tenantId)
                    ->delete();

                if ($deleted > 0) {
                    $this->line("  Dropped {$deleted} existing rows for tenant [{$label}].");
                }
            }

            foreach ($types as $key => $type) {
                $payload = $this->buildPayload($type);

                /** @phpstan-var \Devletes\NotificationsMax\Models\NotificationTypeOverride|null $existing */
                $existing = NotificationTypeOverride::query()
                    ->where('tenant_id', $tenantId)
                    ->where('type_key', $key)
                    ->first();

                if ($existing !== null && ! $this->option('fresh')) {
                    // Already seeded; admin may have customised. Skip rather
                    // than clobber unless --fresh is explicit.
                    $totalSkipped++;

                    continue;
                }

                NotificationTypeOverride::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'type_key' => $key],
                    $payload,
                );

                $totalSeeded++;
            }
        }

        $this->info("Seeded {$totalSeeded} rows; skipped {$totalSkipped} (already present).");

        if ($totalSkipped > 0 && ! $this->option('fresh')) {
            $this->line('Re-run with --fresh to overwrite existing rows.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, ?int>
     */
    protected function resolveTenantIds(): array
    {
        $option = $this->option('tenant');

        if ($option !== null) {
            if (strtolower((string) $option) === 'null') {
                return [null];
            }

            return [(int) $option];
        }

        // No --tenant: seed every tenant_id already present in the table,
        // plus NULL. Hosts wanting blanket coverage across their full
        // tenant list call this command in a loop from their own bootstrap
        // — the package doesn't introspect the host's Tenant model.
        $existing = NotificationTypeOverride::query()
            ->select('tenant_id')
            ->distinct()
            ->pluck('tenant_id')
            ->all();

        $existing[] = null;

        return collect($existing)
            ->unique(fn ($v) => $v === null ? '_' : (string) $v)
            ->values()
            ->all();
    }

    /**
     * Build the seed payload for a type — channel content keyed by channel,
     * each channel's fields populated from the type's `content[$channel]`
     * block when present, otherwise from the back-compat top-level title /
     * body via the same fallback rules the resolver uses at render time.
     *
     * `allowed_channels` is seeded to the type's config-level list so
     * admins start with everything the type supports enabled.
     *
     * @return array<string, mixed>
     */
    protected function buildPayload(NotificationType $type): array
    {
        $channels = config('notifications-max.channels', []);
        $emailTemplates = config('notifications-max.email_templates', []);
        $defaultTemplate = is_array($emailTemplates) && $emailTemplates !== []
            ? array_key_first($emailTemplates)
            : null;

        $channelContent = [];

        foreach ($channels as $channel => $def) {
            $fields = $def['content_fields'] ?? [];

            if ($fields === []) {
                continue;
            }

            $channelContent[$channel] = [];

            foreach (array_keys($fields) as $field) {
                $channelContent[$channel][$field] = match (true) {
                    isset($type->content[$channel][$field]) => $type->content[$channel][$field],
                    $field === 'title' => $type->title,
                    $field === 'body' => $type->body,
                    $field === 'subject' => $type->title,
                    $field === 'template' => $defaultTemplate,
                    default => null,
                };
            }
        }

        return [
            'allowed_channels' => $type->allowedChannels,
            'channel_content' => $channelContent,
        ];
    }
}
