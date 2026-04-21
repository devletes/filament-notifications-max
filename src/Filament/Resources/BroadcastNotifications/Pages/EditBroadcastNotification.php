<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages;

use Devletes\NotificationsMax\Contracts\BroadcastAudienceResolver;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\BroadcastNotificationResource;
use Devletes\NotificationsMax\Jobs\SendBroadcastJob;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditBroadcastNotification extends EditRecord
{
    protected static string $resource = BroadcastNotificationResource::class;

    protected function getHeaderActions(): array
    {
        /** @var BroadcastNotification $broadcast */
        $broadcast = $this->record;

        return [
            Action::make('sendNow')
                ->label('Send now')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn (): bool => $broadcast->sent_at === null)
                ->requiresConfirmation()
                ->modalDescription(function () use ($broadcast): string {
                    $count = app(BroadcastAudienceResolver::class)
                        ->countMatching($broadcast->audience ?? [], $broadcast->tenant_id);

                    return sprintf(
                        'This will notify %d %s immediately. Continue?',
                        $count,
                        $count === 1 ? 'recipient' : 'recipients',
                    );
                })
                ->action(function () use ($broadcast): void {
                    $broadcast->update(['scheduled_at' => null]);
                    dispatch(new SendBroadcastJob($broadcast));

                    Notification::make()
                        ->title('Broadcast queued for immediate send')
                        ->success()
                        ->send();
                }),

            Action::make('testSendToSelf')
                ->label('Test send to myself')
                ->icon('heroicon-o-beaker')
                ->color('gray')
                ->action(function () use ($broadcast): void {
                    $user = auth()->user();

                    if (! $user) {
                        return;
                    }

                    app(NotificationDispatcher::class)->send(
                        'broadcast.admin_custom',
                        [
                            'subject' => $broadcast->subject,
                            'body' => $broadcast->body,
                            'action_url' => $broadcast->action_url,
                            'action_label' => $broadcast->action_label,
                        ],
                        $user,
                    );

                    Notification::make()
                        ->title('Test broadcast sent to you')
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }

    /**
     * Editing a sent broadcast is blocked by the policy; but if this page
     * is reached anyway, redirect users back to the index rather than
     * letting them overwrite historical data.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var BroadcastNotification $broadcast */
        $broadcast = $this->record;

        if ($broadcast->sent_at !== null) {
            // Strip fields that would rewrite history.
            unset($data['subject'], $data['body'], $data['audience'], $data['channels']);
        }

        return $data;
    }
}
