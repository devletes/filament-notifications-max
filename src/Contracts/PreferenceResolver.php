<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Decides which channels a notification of a given type should be delivered
 * through, for a given user.
 *
 * The package ships {@see \Devletes\NotificationsMax\Defaults\EloquentPreferenceResolver}
 * as the default — it consults the `user_notification_preferences` table and
 * falls back to registry defaults. Host apps swap this out to introduce
 * cross-cutting policies such as quiet hours, on-call schedules, per-tenant
 * channel restrictions, or out-of-band preference storage (cache, JSON
 * column, third-party prefs API, etc.).
 *
 * Mandatory types must always be delivered through every allowed channel —
 * implementations that ignore this invariant will silently break compliance
 * notifications. Use {@see \Devletes\NotificationsMax\Registry\NotificationType::$mandatory}
 * to detect and short-circuit.
 */
interface PreferenceResolver
{
    /**
     * @return array<int, string>  Channel names ('database', 'broadcast', 'mail', ...)
     */
    public function channelsFor(Authenticatable $user, string $typeKey): array;
}
