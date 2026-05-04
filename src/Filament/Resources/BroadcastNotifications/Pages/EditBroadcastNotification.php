<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages;

use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\BroadcastNotificationResource;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Form-based edit page for mutable broadcasts. Reachable only via the Edit
 * action on the view page — direct URL access is gated by the policy, which
 * blocks updates once a broadcast has been sent.
 *
 * Publish, Test-send, and Delete live on the view page so there is one
 * canonical place for lifecycle actions. After a save, Filament's default
 * behaviour is to stay on the edit page; we redirect back to view instead
 * so the admin lands on the read-only summary + audience list.
 */
class EditBroadcastNotification extends EditRecord
{
    protected static string $resource = BroadcastNotificationResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * Editing a sent broadcast is blocked by the policy; but if this page
     * is reached anyway, strip fields that would rewrite history.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var BroadcastNotification $broadcast */
        $broadcast = $this->record;

        if ($broadcast->isSent()) {
            unset($data['subject'], $data['body'], $data['audience'], $data['channels']);
        }

        return $data;
    }
}
