<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Support;

/**
 * Expand logical channel names (push, email, slack, …) into the physical
 * Laravel notification channels (database, broadcast, mail, …) declared by
 * each channel's `physical` config key.
 *
 * Used at two call sites that both need to bridge the registry's logical
 * channel vocabulary to Laravel's physical via() list:
 *   - {@see \Devletes\NotificationsMax\Defaults\EloquentPreferenceResolver}
 *     when materialising the user's resolved channels.
 *   - {@see \Devletes\NotificationsMax\Notifications\GenericNotification::via()}
 *     when narrowing the resolved set by a per-message `context.channels`
 *     override (e.g. an admin's broadcast composer selecting Slack only).
 */
final class ChannelExpander
{
    /**
     * @param  array<int, string>  $logical
     * @return array<int, string>
     */
    public static function toPhysical(array $logical): array
    {
        $channels = config('notifications-max.channels', []);

        $physical = [];

        foreach ($logical as $channel) {
            $def = $channels[$channel] ?? null;

            if (is_array($def) && isset($def['physical']) && is_array($def['physical'])) {
                $physical = array_merge($physical, $def['physical']);

                continue;
            }

            // No registry entry — caller is using a physical channel name
            // directly, or a custom channel without a registry definition.
            $physical[] = $channel;
        }

        return array_values(array_unique($physical));
    }
}
