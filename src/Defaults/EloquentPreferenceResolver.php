<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Defaults;

use Devletes\NotificationsMax\Contracts\PreferenceResolver;
use Devletes\NotificationsMax\Registry\NotificationTypeRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Default {@see PreferenceResolver} implementation. Combines three inputs:
 *
 *   1. The type's registry definition (default_channels, allowed_channels,
 *      mandatory flag). These use *logical* channels like "push" and "email".
 *   2. The user's explicit preference rows in `user_notification_preferences`,
 *      also stored as logical channels.
 *   3. Safety fallbacks when the preferences table doesn't exist yet
 *      (during a fresh install before migrations run).
 *
 * Resolution rule:
 *   - Mandatory types → all allowed_channels, preferences ignored.
 *   - Otherwise: for each allowed channel, prefer an explicit row;
 *     fall back to the default_channels membership.
 *
 * The resolved *logical* channel list is then expanded to the *physical*
 * Laravel notification channels Laravel's dispatcher actually routes
 * (database / broadcast / mail) via the `notifications-max.channels`
 * config map. "push" expands to both database + broadcast so a user who
 * enables push receives both the persisted bell entry and the transient
 * toast — a toast without a bell entry would vanish forever and is never
 * the intended UX.
 *
 * Host apps wanting different behaviour (quiet hours, per-tenant policy,
 * non-DB storage) bind their own implementation against the
 * {@see PreferenceResolver} contract in `AppServiceProvider::register()`.
 */
class EloquentPreferenceResolver implements PreferenceResolver
{
    protected static ?bool $tableExists = null;

    public function __construct(protected NotificationTypeRegistry $registry) {}

    /**
     * @return array<int, string>
     */
    public function channelsFor(Authenticatable $user, string $typeKey): array
    {
        $type = $this->registry->find($typeKey);

        if ($type->mandatory) {
            return $this->expandLogicalChannels($type->allowedChannels);
        }

        // If the preferences table hasn't been migrated yet, fall back to
        // the type's defaults so fresh installs still deliver notifications.
        if (! $this->preferencesTableExists()) {
            return $this->expandLogicalChannels($type->defaultChannels);
        }

        $explicit = $this->loadExplicit($user, $typeKey);

        $logical = collect($type->allowedChannels)
            ->filter(function (string $channel) use ($type, $explicit) {
                if (array_key_exists($channel, $explicit)) {
                    return (bool) $explicit[$channel];
                }

                return $type->channelIsOnByDefault($channel);
            })
            ->values()
            ->all();

        return $this->expandLogicalChannels($logical);
    }

    /**
     * Expand logical channel names (push, email) into the physical Laravel
     * notification channels (database, broadcast, mail). Unrecognised names
     * are passed through unchanged so host apps can use physical channels
     * directly if they want to bypass the mapping for a specific type.
     *
     * @param  array<int, string>  $logical
     * @return array<int, string>
     */
    protected function expandLogicalChannels(array $logical): array
    {
        $map = config('notifications-max.channels', [
            'push' => ['database', 'broadcast'],
            'email' => ['mail'],
        ]);

        $physical = [];

        foreach ($logical as $channel) {
            if (isset($map[$channel]) && is_array($map[$channel])) {
                $physical = array_merge($physical, $map[$channel]);

                continue;
            }

            $physical[] = $channel;
        }

        return array_values(array_unique($physical));
    }

    /**
     * @return array<string, bool>  channel => enabled
     */
    protected function loadExplicit(Authenticatable $user, string $typeKey): array
    {
        $userId = method_exists($user, 'getAuthIdentifier')
            ? $user->getAuthIdentifier()
            : ($user->id ?? null);

        if ($userId === null) {
            return [];
        }

        $rows = DB::table('user_notification_preferences')
            ->where('user_id', $userId)
            ->where('notification_type_key', $typeKey)
            ->get(['channel', 'enabled']);

        return $rows
            ->mapWithKeys(fn ($row) => [$row->channel => (bool) $row->enabled])
            ->all();
    }

    protected function preferencesTableExists(): bool
    {
        if (static::$tableExists !== null) {
            return static::$tableExists;
        }

        return static::$tableExists = Schema::hasTable('user_notification_preferences');
    }
}
