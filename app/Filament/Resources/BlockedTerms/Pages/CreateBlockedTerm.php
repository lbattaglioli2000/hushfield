<?php

namespace App\Filament\Resources\BlockedTerms\Pages;

use App\Filament\Resources\BlockedTerms\BlockedTermResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlockedTerm extends CreateRecord
{
    protected static string $resource = BlockedTermResource::class;
}
