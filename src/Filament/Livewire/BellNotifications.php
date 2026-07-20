<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Livewire;

use Filament\Livewire\DatabaseNotifications;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Notifications\DatabaseNotificationCollection;

/**
 * Panel bell-dropdown notifications list — capped and never paginated.
 *
 * Filament's stock component gives the bell two bad options: paginate in
 * pages of 50 (a "Next"/"Previous" control the user has to click through
 * inside a dropdown), or — with pagination off — dump the user's ENTIRE
 * unbounded backlog into the dropdown. Neither is what a bell wants.
 *
 * This override renders only the most recent notifications (config
 * `notifications-max.bell.limit`, default 20) with no pagination. The full
 * history stays one click away via the "View all" link the package's
 * `database-notifications` view adds, which opens the NotificationCenter page.
 *
 * It extends the PANEL component ({@see \Filament\Livewire\DatabaseNotifications},
 * not the base {@see \Filament\Notifications\Livewire\DatabaseNotifications})
 * so it keeps the topbar/sidebar trigger, `Filament::auth()` user resolution
 * and per-panel polling — the panel renders its bell by resolving this class
 * directly ({@see \Filament\Panel\Concerns\HasNotifications::getDatabaseNotificationsLivewireComponent()}),
 * never the `database-notifications` Livewire alias. Wired in per-panel from
 * {@see \Devletes\NotificationsMax\NotificationsMaxPlugin::register()}.
 */
class BellNotifications extends DatabaseNotifications
{
    /**
     * Return the most recent notifications, capped, as a plain collection.
     *
     * Returning a Collection rather than a Paginator is what suppresses the
     * pagination footer: the view only renders it when `$notifications` is a
     * Paginator with pages. The notifications relation is already ordered
     * newest-first (Laravel's `Notifiable::notifications()` applies
     * `->latest()`), so a bare limit yields the newest rows — the same order
     * Filament's own paginated query relies on.
     */
    public function getNotifications(): DatabaseNotificationCollection | Paginator
    {
        /** @phpstan-ignore-next-line */
        return $this->getNotificationsQuery()
            ->limit($this->getBellLimit())
            ->get();
    }

    public function isPaginated(): bool
    {
        return false;
    }

    /**
     * How many notifications the bell dropdown renders. A non-positive or
     * non-numeric config value falls back to the built-in default of 20.
     */
    protected function getBellLimit(): int
    {
        $limit = (int) config('notifications-max.bell.limit', 20);

        return $limit > 0 ? $limit : 20;
    }
}
