<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Channels;

use Devletes\NotificationsMax\Contracts\ChannelHandler;
use Devletes\NotificationsMax\Notifications\GenericNotification;
use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Renders the toast payload Laravel's broadcast channel ships over the
 * realtime connection (Reverb / Pusher). The toast id is a per-instance
 * UUID — decoupled from the database row id — so close-events on the
 * toast don't accidentally delete the bell row. See
 * {@see GenericNotification::resolveBroadcastData()} for the full
 * rationale.
 */
class BroadcastChannelHandler implements ChannelHandler
{
    public function send(object $notifiable, GenericNotification $notification): BroadcastMessage
    {
        return new BroadcastMessage($notification->resolveBroadcastData());
    }
}
