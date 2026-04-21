<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Defaults;

use Devletes\NotificationsMax\Contracts\AdminRoleResolver;
use Devletes\NotificationsMax\Contracts\AuthorizedBroadcaster;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Default per-broadcast authorization. Delegates to
 * AdminRoleResolver::canBroadcast() — i.e. if you can use the broadcaster at
 * all, you can send any broadcast. Host apps override to enforce finer-grained
 * rules (company-wide requires executive role, etc.).
 */
class DefaultAuthorizedBroadcaster implements AuthorizedBroadcaster
{
    public function __construct(protected AdminRoleResolver $adminRoleResolver) {}

    public function canSend(Authenticatable $user, object $broadcast): bool
    {
        return $this->adminRoleResolver->canBroadcast($user);
    }
}
