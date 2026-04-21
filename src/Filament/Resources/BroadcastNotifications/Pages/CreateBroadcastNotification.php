<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages;

use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Devletes\NotificationsMax\Contracts\TenantResolver;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\BroadcastNotificationResource;
use Devletes\NotificationsMax\Jobs\SendBroadcastJob;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * Stamp tenant + creator fields on save, then either send immediately or
 * schedule via `->delay()` depending on whether `scheduled_at` was provided.
 */
class CreateBroadcastNotification extends CreateRecord
{
    protected static string $resource = BroadcastNotificationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = app(TenantResolver::class)->currentId();
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var BroadcastNotification $broadcast */
        $broadcast = $this->record;

        // Confirm with the admin how many users this will reach so they
        // have a number to compare against once `recipients_count` stamps
        // post-send.
        $count = app(BroadcastAudienceResolver::class)
            ->countMatching($broadcast->audience ?? [], $broadcast->tenant_id);

        $this->dispatchBroadcast($broadcast);

        Notification::make()
            ->title($broadcast->scheduled_at ? 'Broadcast scheduled' : 'Broadcast sent')
            ->body(sprintf('Reaching %d %s.', $count, $count === 1 ? 'recipient' : 'recipients'))
            ->success()
            ->send();
    }

    protected function dispatchBroadcast(BroadcastNotification $broadcast): void
    {
        $job = new SendBroadcastJob($broadcast);

        if ($broadcast->scheduled_at && $broadcast->scheduled_at->isFuture()) {
            // Delay until the scheduled moment — no separate cron sweep
            // needed. Requires a queue driver that supports delays (database,
            // redis, sqs). Sync driver will dispatch immediately, ignoring
            // the delay — fine for local testing.
            dispatch($job)->delay($broadcast->scheduled_at);

            return;
        }

        dispatch($job);
    }
}
