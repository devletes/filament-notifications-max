<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Support;

/**
 * Expand logical channel names (push, email, slack, …) to the physical
 * Laravel channels declared by each channel's `physical` config key.
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

            // Unmapped name passes through as a physical channel.
            $physical[] = $channel;
        }

        return array_values(array_unique($physical));
    }
}
