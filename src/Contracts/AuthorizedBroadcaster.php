<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Optional finer-grained authorization for sending a *specific* admin
 * broadcast, beyond the general "can operate the broadcaster" check provided
 * by AdminRoleResolver::canBroadcast().
 *
 * Use this when some broadcasts require additional authorization — for
 * example: "only C-suite can send company-wide announcements", or "HR can
 * only broadcast to their own department".
 *
 * The default implementation delegates to AdminRoleResolver::canBroadcast()
 * unconditionally; host apps replace it when finer control is needed.
 */
interface AuthorizedBroadcaster
{
    /**
     * @param  object  $broadcast  A BroadcastNotification model instance
     */
    public function canSend(Authenticatable $user, object $broadcast): bool;
}
