<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages;

use Devletes\NotificationsMax\Contracts\BroadcastReleasePipeline;
use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\BroadcastNotificationResource;
use Devletes\NotificationsMax\Models\BroadcastNotification;
use Devletes\NotificationsMax\Services\NotificationDispatcher;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Default landing page when an admin clicks a broadcast in the list.
 *
 * Shows a read-only infolist of the broadcast's message + delivery metadata
 * and a paginated, searchable relation-manager table of the matching
 * audience. All lifecycle actions (Publish, Test, Edit, Delete) hang off
 * this page's header — the edit page is only reached via the Edit action
 * when the broadcast is still mutable.
 */
class ViewBroadcastNotification extends ViewRecord
{
    protected static string $resource = BroadcastNotificationResource::class;

    protected function getHeaderActions(): array
    {
        /** @var BroadcastNotification $broadcast */
        $broadcast = $this->record;

        // Ask the pipeline how to present the action for this broadcast
        // state. Immediate pipeline → "Publish" / "Schedule" with recipient
        // count. Approval pipeline → "Submit for Approval" on draft,
        // "Publish" on approved. Host pipelines get full control over
        // copy without the resource page having to know their semantics.
        $prompt = app(BroadcastReleasePipeline::class)->describeAction($broadcast);

        return [
            Action::make('publish')
                ->label($prompt->label)
                ->icon($prompt->icon)
                ->color($prompt->color)
                ->authorize('publish')
                ->requiresConfirmation()
                ->modalDescription($prompt->confirmation)
                ->action(function () use ($broadcast): void {
                    // The pipeline decides what publishing means for this
                    // install — the shipped default queues the fan-out job,
                    // a host-app approval pipeline might instead park the
                    // broadcast in a pending state and short-circuit until
                    // the approval clears.
                    $result = app(BroadcastReleasePipeline::class)->handle($broadcast);

                    Notification::make()
                        ->title($result->title)
                        ->body($result->body)
                        ->success()
                        ->send();

                    $this->redirect(BroadcastNotificationResource::getUrl('index'));
                }),

            Action::make('testSendToSelf')
                ->label('Test send to myself')
                ->icon('heroicon-o-beaker')
                ->color('gray')
                // Only meaningful while the broadcast is still in its
                // pre-release drafting state. Once it's been published
                // (queued / scheduled / sent / host workflow statuses),
                // the admin should be inspecting real delivery, not
                // re-testing copy.
                ->visible(fn (): bool => $broadcast->status === config('notifications-max.broadcaster.initial_status', 'draft'))
                ->action(function () use ($broadcast): void {
                    $user = auth()->user();

                    if (! $user) {
                        return;
                    }

                    $context = [
                        'subject' => $broadcast->subject,
                        'body' => $broadcast->body,
                        'action_url' => $broadcast->action_url,
                        'action_label' => $broadcast->action_label,
                    ];

                    // Honour the composer's channel selection on test sends
                    // too — admin testing "Slack only" should see Slack only,
                    // not the full default set for broadcast.admin_custom.
                    if (! empty($broadcast->channels)) {
                        $context['channels'] = $broadcast->channels;
                    }

                    app(NotificationDispatcher::class)->send(
                        'broadcast.admin_custom',
                        $context,
                        $user,
                    );

                    Notification::make()
                        ->title('Test broadcast sent to you')
                        ->success()
                        ->send();
                }),

            EditAction::make()
                // Only editable in the initial state. Once the broadcast
                // has been submitted (pending_approval / approved / queued /
                // scheduled / sent), mutating content would rewrite what
                // reviewers saw or what recipients were told to expect. A
                // rejection rolls the row back to draft via
                // HandlesApprovalLifecycle::transitionBackToDraft(), so
                // admins can revise and resubmit from there.
                ->visible(fn (): bool => $broadcast->status === config('notifications-max.broadcaster.initial_status', 'draft')),

            // Delete is only available pre-release. Once published (queued /
            // scheduled / sent / host workflow statuses), the row is part of
            // a delivery record and deleting would lose audit context.
            DeleteAction::make()
                ->visible(fn (): bool => $broadcast->status === config('notifications-max.broadcaster.initial_status', 'draft')),
        ];
    }
}
