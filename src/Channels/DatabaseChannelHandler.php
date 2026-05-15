<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Channels;

use Devletes\NotificationsMax\Contracts\ChannelHandler;
use Devletes\NotificationsMax\Notifications\GenericNotification;

/**
 * Renders the payload Laravel's database channel persists to the
 * `notifications` table. The Filament-formatted shape (built via
 * `FilamentNotification::getDatabaseMessage()` inside the notification)
 * is what the bell-panel Livewire component knows how to deserialize, so
 * we delegate to it rather than rebuilding the shape here.
 *
 * Host apps wanting a different on-disk shape (e.g. flattened columns,
 * extra audit fields) override `notifications-max.channel_handlers.database`
 * with their own implementation.
 */
class DatabaseChannelHandler implements ChannelHandler
{
    /**
     * @return array<string, mixed>
     */
    public function send(object $notifiable, GenericNotification $notification): array
    {
        return $notification->buildFilamentPayload();
    }
}
