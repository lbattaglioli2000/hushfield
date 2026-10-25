<?php

namespace App\Filament\Resources\BlockedTerms\Pages;

use App\Filament\Resources\BlockedTerms\BlockedTermResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBlockedTerm extends EditRecord
{
    protected static string $resource = BlockedTermResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
