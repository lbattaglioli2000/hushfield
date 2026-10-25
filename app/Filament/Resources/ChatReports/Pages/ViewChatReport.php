<?php

namespace App\Filament\Resources\ChatReports\Pages;

use App\Filament\Resources\ChatReports\Actions\ResolveReportAction;
use App\Filament\Resources\ChatReports\ChatReportResource;
use Filament\Resources\Pages\ViewRecord;

class ViewChatReport extends ViewRecord
{
    protected static string $resource = ChatReportResource::class;

    protected function getHeaderActions(): array
    {
        return ResolveReportAction::all();
    }
}
