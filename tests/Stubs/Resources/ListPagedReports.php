<?php

declare(strict_types=1);

namespace Devletes\NotificationsMax\Tests\Stubs\Resources;

use Filament\Resources\Pages\ListRecords;

class ListPagedReports extends ListRecords
{
    protected static string $resource = PagedReportResource::class;
}
