<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Listeners;

use Devletes\NotificationsMax\Notifications\GenericNotification;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;

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

        // DatabaseNotificationsSent implements ShouldBroadcast, so this
        // dispatch synchronously invokes Laravel's broadcaster (Reverb/Pusher).
        // A dev environment without Reverb running, or a transient socket
        // outage in prod, would otherwise surface as a 500 to the triggering
        // request — even though the database channel already persisted the
        // bell row successfully. Degrade the failure to a log line: the user
        // still sees their notification on the next bell poll (30s polling
        // fallback), they just miss the real-time push for this one event.
        //
        // The catch is BroadcastException-specific (rather than the broader
        // Throwable net the dispatcher uses) so we can log a meaningful
        // "real-time bell refresh failed" message instead of attributing
        // the failure to the notification itself. Other exceptions still
        // propagate to the dispatcher's outer handler.
        try {
            DatabaseNotificationsSent::dispatch($event->notifiable);
        } catch (BroadcastException $e) {
            Log::warning('notifications-max: real-time bell refresh failed', [
                'notifiable_type' => $event->notifiable::class,
                'notifiable_id' => $event->notifiable->getAuthIdentifier(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
