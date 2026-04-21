<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Listeners;

use Devletes\NotificationsMax\Notifications\GenericNotification;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Bridges Laravel's standard notification pipeline to Filament's bell UI.
 *
 * Background: Filament's bell dropdown (`DatabaseNotifications` Livewire
 * component) subscribes to the `.database-notifications.sent` broadcast
 * event on the authenticated user's private channel. That event is only
 * fired by Filament's own `Notification::sendToDatabase($user, isEventDispatched: true)`
 * API — not by Laravel's standard `Notification::send()` pipeline we use.
 *
 * Without this listener, the database row persists but the bell never
 * refreshes until its 30-second polling interval elapses.
 *
 * With this listener, every database-persisted GenericNotification fires
 * DatabaseNotificationsSent, which broadcasts to the user's private channel
 * and triggers the bell's auto-refresh in real time.
 */
class FireDatabaseNotificationsSent
{
    public function handle(NotificationSent $event): void
    {
        // Only interested in the database channel — broadcast channel and
        // mail channel have their own delivery mechanisms.
        if ($event->channel !== 'database') {
            return;
        }

        // Only fire for notifications originating in this package. Other
        // notifications (e.g. Filament's own sendToDatabase path) handle
        // their own event dispatch.
        if (! $event->notification instanceof GenericNotification) {
            return;
        }

        // The event expects a User-like notifiable. Defensive check.
        if (! $event->notifiable instanceof Authenticatable) {
            return;
        }

        DatabaseNotificationsSent::dispatch($event->notifiable);
    }
}
