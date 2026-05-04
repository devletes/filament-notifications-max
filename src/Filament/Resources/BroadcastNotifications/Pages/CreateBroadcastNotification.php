<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages;

use Devletes\NotificationsMax\Contracts\TenantResolver;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\BroadcastNotificationResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Draft-first creation. Saving a new broadcast persists a row in the
 * initial status (configurable via `notifications-max.broadcaster.initial_status`,
 * default `'draft'`) and sends the admin to the edit page. Nothing is
 * queued and no recipients are touched at this stage — the Publish action
 * on the edit page is what kicks off dispatch via the release pipeline.
 *
 * Why draft-first: it gives admins a chance to review audience size and
 * message copy before committing, and it gives host-app workflows (approval
 * gates, moderation queues) a natural hook point without the package having
 * to know those workflows exist.
 */
class CreateBroadcastNotification extends CreateRecord
{
    protected static string $resource = BroadcastNotificationResource::class;

    /**
     * Land on the view page after save — that's where the audience preview
     * lives, where the Publish action is mounted, and (post-send) where
     * delivery stats will appear. The edit page stays reachable from the
     * view page's Edit header action.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = app(TenantResolver::class)->currentId();
        $data['created_by'] = auth()->id();
        $data['status'] = config('notifications-max.broadcaster.initial_status', 'draft');

        return $data;
    }
}
