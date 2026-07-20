<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Tests\Stubs\Resources;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;

/**
 * Resource stub WITHOUT a 'view' page — records open in a `ViewAction`
 * modal on the list page, so `/{panel}/tasks/{id}` is not a route here.
 * This is the shape the resolver's automatic table-action detection
 * exists for.
 */
class ModalTaskResource extends Resource
{
    protected static ?string $slug = 'tasks';

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListModalTasks::route('/'),
        ];
    }
}
