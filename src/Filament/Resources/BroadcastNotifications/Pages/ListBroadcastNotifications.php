<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\Pages;

use Devletes\NotificationsMax\Filament\Resources\BroadcastNotifications\BroadcastNotificationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBroadcastNotifications extends ListRecords
{
    protected static string $resource = BroadcastNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Compose broadcast'),
        ];
    }
}
