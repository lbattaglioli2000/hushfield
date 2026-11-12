<?php

namespace App\Filament\Resources\LibraryShelves\Pages;

use App\Filament\Resources\LibraryShelves\LibraryShelfResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLibraryShelves extends ListRecords
{
    protected static string $resource = LibraryShelfResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
