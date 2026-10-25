<?php

namespace App\Filament\Resources\BlockedTerms\Pages;

use App\Filament\Resources\BlockedTerms\Actions\TestFilterAction;
use App\Filament\Resources\BlockedTerms\BlockedTermResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBlockedTerms extends ListRecords
{
    protected static string $resource = BlockedTermResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TestFilterAction::make(),
            CreateAction::make(),
        ];
    }
}
