<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Tests\Stubs\Resources;

use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;

/**
 * Resource stub WITH a 'view' page — the counterpart to
 * {@see ModalTaskResource}. Detection must leave these on the
 * `/{panel}/reports/{id}` detail-path form.
 */
class PagedReportResource extends Resource
{
    protected static ?string $slug = 'reports';

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPagedReports::route('/'),
            'view' => ViewPagedReport::route('/{record}'),
        ];
    }
}
